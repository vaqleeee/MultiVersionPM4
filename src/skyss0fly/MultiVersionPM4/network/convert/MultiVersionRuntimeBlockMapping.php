<?php

declare(strict_types=1);

namespace skyss0fly\MultiVersionPM4\network\convert;

use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\serializer\NetworkNbtSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\utils\Filesystem;
use skyss0fly\MultiVersionPM4\Loader;
use skyss0fly\MultiVersionPM4\network\ProtocolConstants;
use pocketmine\block\BlockLegacyIds;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\R12ToCurrentBlockMapEntry;
use pocketmine\utils\BinaryStream;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\utils\AssumptionFailedError;
use function file_get_contents;
use function json_decode;

class MultiVersionRuntimeBlockMapping
{

    /** @var int[][] */
    private static $legacyToRuntimeMap = [];
    /** @var int[][] */
    private static $runtimeToLegacyMap = [];
    /** @var CompoundTag[][]|null */
    private static $bedrockKnownStates = [];

    const PROTOCOL = [
        ProtocolConstants::BEDROCK_1_16_220 => "_1_16_220",
        ProtocolConstants::BEDROCK_1_17_0 => "_1_17_0",
        ProtocolConstants::BEDROCK_1_17_10 => "_1_17_10",
        ProtocolConstants::BEDROCK_1_17_30 => "_1_17_30",
        ProtocolConstants::BEDROCK_1_17_40 => "_1_17_40",
       // ProtocolConstants::BEDROCK_1_18_0 => "_1_18_0",
        ProtocolConstants::BEDROCK_1_19_20 => "_1_19_20"
    ];

    private function __construct()
    {
        //NOOP
    }

    public static function init(): void
    {
        foreach (self::PROTOCOL as $protocol => $fileName) {
            if (Loader::getInstance()->isProtocolDisabled($protocol)) {
                continue;
            }
            $canonicalBlockStatesFile = file_get_contents(Loader::$resourcesPath . "vanilla/canonical_block_states" . $fileName . ".nbt");
            if ($canonicalBlockStatesFile === false) {
                throw new AssumptionFailedError("Missing required resource file");
            }
            $stream = PacketSerializer::decoder($canonicalBlockStatesFile, 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
            $list = [];
            while (!$stream->feof()) {
                $list[] = $stream->getNbtCompoundRoot();
            }
            self::$bedrockKnownStates[$protocol] = $list;

            self::setupLegacyMappings($protocol);
        }
    }

    private static function setupLegacyMappings(int $protocol): void{
        $legacyIdMap = json_decode(file_get_contents(Loader::$resourcesPath . "vanilla/block_id_map.json"), true);

        /** @var R12ToCurrentBlockMapEntry[] $legacyStateMap */
        $legacyStateMap = [];
        switch ($protocol) {
            case ProtocolConstants::BEDROCK_1_17_0:
            case ProtocolConstants::BEDROCK_1_17_10:
                $suffix = self::PROTOCOL[ProtocolConstants::BEDROCK_1_17_10];
                break;
            case ProtocolConstants::BEDROCK_1_17_40:
            case ProtocolConstants::BEDROCK_1_17_30:
                $suffix = self::PROTOCOL[ProtocolConstants::BEDROCK_1_17_30];
                break;
            default:
                $suffix = self::PROTOCOL[$protocol];
                break;
        }
        $path = Loader::$resourcesPath . "vanilla/r12_to_current_block_map" . $suffix . ".bin";
        $legacyStateMapReader = PacketSerializer::decoder(file_get_contents($path), 0, new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary()));
        $nbtReader = new NetworkNbtSerializer();
        while (!$legacyStateMapReader->feof()) {
            $id = $legacyStateMapReader->getString();
            $meta = $legacyStateMapReader->getLShort();

            $offset = $legacyStateMapReader->getOffset();
            $state = $nbtReader->read($legacyStateMapReader->getBuffer(), $offset)->mustGetCompoundTag();
            $legacyStateMapReader->setOffset($offset);
            $legacyStateMap[] = new R12ToCurrentBlockMapEntry($id, $meta, $state);
        }

        /**
         * @var int[][] $idToStatesMap string id -> int[] list of candidate state indices
         */
        $idToStatesMap = [];
        foreach (self::$bedrockKnownStates[$protocol] as $k => $state) {
            $idToStatesMap[$state->getString("name")][] = $k;
        }
        foreach ($legacyStateMap as $pair) {
            $id = $legacyIdMap[$pair->getId()] ?? null;
            if ($id === null) {
                throw new \RuntimeException("No legacy ID matches " . $pair->getId());
            }
            $data = $pair->getMeta();
            if ($data > 15) {
                //we can't handle metadata with more than 4 bits
                continue;
            }
            $mappedState = $pair->getBlockState();
            $mappedName = $mappedState->getString("name");
            if (!isset($idToStatesMap[$mappedName])) {
                throw new \RuntimeException("Mapped new state does not appear in network table");
            }
            foreach ($idToStatesMap[$mappedName] as $k) {
                $networkState = self::$bedrockKnownStates[$protocol][$k];
                if ($mappedState->equals($networkState)) {
                    self::registerMapping($k, $id, $data, $protocol);
                    continue 2;
                }
            }
            throw new \RuntimeException("Mapped new state does not appear in network table");
        }
    }


    private static function lazyInit() : void{
        if(self::$bedrockKnownStates === null){
            self::init();
        }
    }

    public static function toStaticRuntimeId(int $id, int $meta = 0, int $protocol = ProtocolInfo::CURRENT_PROTOCOL) : int{
        if($protocol === ProtocolConstants::BEDROCK_1_18_0) {
            $protocol = ProtocolConstants::BEDROCK_1_17_40;
        }
        self::lazyInit();
        /*
         * try id+meta first
         * if not found, try id+0 (strip meta)
         * if still not found, return update! block
         */
        return self::$legacyToRuntimeMap[$protocol][($id << 4) | $meta] ?? self::$legacyToRuntimeMap[$protocol][$id << 4] ?? self::$legacyToRuntimeMap[$protocol][BlockLegacyIds::INFO_UPDATE << 4];
    }

    /**
     * @return int[] [id, meta]
     */
    public static function fromStaticRuntimeId(int $runtimeId, int $protocol) : array{
        if($protocol === ProtocolConstants::BEDROCK_1_18_0) {
            $protocol = ProtocolConstants::BEDROCK_1_17_40;
        }
        self::lazyInit();
        $v = self::$runtimeToLegacyMap[$protocol][$runtimeId] ?? null;
        if($v === null) {
            return [0, 0];
        }
        return [$v >> 4, $v & 0xf];
    }

    private static function registerMapping(int $staticRuntimeId, int $legacyId, int $legacyMeta, $protocol) : void{
        self::$legacyToRuntimeMap[$protocol][($legacyId << 4) | $legacyMeta] = $staticRuntimeId;
        self::$runtimeToLegacyMap[$protocol][$staticRuntimeId] = ($legacyId << 4) | $legacyMeta;
    }

    /**
     * @return CompoundTag[]
     */
    public static function getBedrockKnownStates() : array{
        self::lazyInit();
        return self::$bedrockKnownStates;
    }
}
