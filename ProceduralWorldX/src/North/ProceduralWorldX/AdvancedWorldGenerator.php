<?php

declare(strict_types=1);

namespace North\ProceduralWorldX\AdvancedWorldGenerator;

use pocketmine\world\ChunkManager;
use pocketmine\world\format\Chunk;
use pocketmine\world\generator\Generator;
use pocketmine\utils\Config;
use pocketmine\math\Vector3;
use pocketmine\block\BlockFactory;
use pocketmine\block\Block;

class AdvancedWorldGenerator extends Generator {
    
    private Config $mainConfig;
    private Config $biomesConfig;
    private Config $structuresConfig;
    
    private array $noiseCache = [];
    private array $biomeCache = [];
    
    private PerlinNoise $terrainNoise;
    private VoronoiNoise $biomeNoise;
    private PerlinNoise $caveNoise;
    
    public function __construct(int $seed, string $preset) {
        parent::__construct($seed, $preset);
        
        $this->loadConfigs();
        
        $this->initializeNoiseAlgorithms();
    }
    
    private function loadConfigs(): void {
        $this->mainConfig = new Config($this->getDataFolder() . "config.yml", Config::YAML);
        $this->biomesConfig = new Config($this->getDataFolder() . "biomes.yml", Config::YAML);
        $this->structuresConfig = new Config($this->getDataFolder() . "structures.yml", Config::YAML);
    }
    
    private function getDataFolder(): string {
        return "./plugin_data/ProceduralWorldX/";
    }
    
    private function initializeNoiseAlgorithms(): void {
        $settings = $this->mainConfig->get("world-generator");
        
        $terrainAlgo = $settings['algorithms']['terrain'];
        $this->terrainNoise = new PerlinNoise(
            $this->seed,
            $terrainAlgo['octaves'],
            $terrainAlgo['persistence'],
            $terrainAlgo['scale']
        );
        
        $biomeAlgo = $settings['algorithms']['biome'];
        $this->biomeNoise = new VoronoiNoise(
            $this->seed,
            $biomeAlgo['points'],
            $biomeAlgo['scale']
        );
        
        $caveAlgo = $settings['algorithms']['cave'];
        $this->caveNoise = new PerlinNoise(
            $this->seed + 1,
            3,
            0.5,
            $caveAlgo['scale']
        );
    }
    
    public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ): void {
        $chunk = $world->getChunk($chunkX, $chunkZ);
        
        $this->generateBaseTerrain($chunk, $chunkX, $chunkZ);
        
        $this->applyBiomes($chunk, $chunkX, $chunkZ);
        
        $this->generateCaves($chunk, $chunkX, $chunkZ);
        
        $this->generateStructures($chunk, $chunkX, $chunkZ);
        
        $this->decorateTerrain($chunk, $chunkX, $chunkZ);
    }
    
    private function generateBaseTerrain(Chunk $chunk, int $chunkX, int $chunkZ): void {
        $seaLevel = $this->mainConfig->getNested("world-generator.settings.sea-level", 62);
        
        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                $globalX = $chunkX * 16 + $x;
                $globalZ = $chunkZ * 16 + $z;
                
                $height = $this->getTerrainHeight($globalX, $globalZ);
                
                $this->generateTerrainLayers($chunk, $x, $z, $height);
                
                if ($height < $seaLevel) {
                    $this->generateWater($chunk, $x, $z, $height, $seaLevel);
                }
            }
        }
    }
    
    private function getTerrainHeight(int $x, int $z): int {
        $cacheKey = $x . ":" . $z;
        
        if (isset($this->noiseCache[$cacheKey])) {
            return $this->noiseCache[$cacheKey];
        }
        
        $noise = $this->terrainNoise->noise2D($x, $z, true);
        
        $noise = $this->applyTerrainCurve($noise);
        
        $settings = $this->mainConfig->getNested("world-generator.settings");
        $minHeight = 0;
        $maxHeight = $settings['total-height'] ?? 128;
        
        $height = (int)($minHeight + ($noise + 1) * 0.5 * ($maxHeight - $minHeight));
        
        $this->noiseCache[$cacheKey] = $height;
        return $height;
    }
    
    private function applyTerrainCurve(float $noise): float {
        $curveType = $this->mainConfig->getNested("world-generator.algorithms.terrain.curve", "standard");
        
        switch ($curveType) {
            case "mountains":
                return $noise * abs($noise);
            case "hills":
                return sin($noise * M_PI) * 0.5 + 0.5;
            case "plains":
            default:
                return $noise;
        }
    }
    
    private function generateTerrainLayers(Chunk $chunk, int $x, int $z, int $surfaceHeight): void {
        $layers = $this->mainConfig->getNested("world-generator.terrain-layers", []);
        $currentDepth = 0;
        
        foreach ($layers as $layer) {
            $depth = $layer['depth'];
            $blockId = $this->getBlockIdFromString($layer['block']);
            
            for ($y = $surfaceHeight - $currentDepth; $y > $surfaceHeight - $currentDepth - $depth && $y >= 0; $y--) {
                $chunk->setBlockStateId($x, $y, $z, $blockId);
            }
            
            $currentDepth += $depth;
        }
        
        for ($y = $surfaceHeight - $currentDepth; $y >= 0; $y--) {
            $chunk->setBlockStateId($x, $y, $z, Block::STONE);
        }
    }
    
    private function applyBiomes(Chunk $chunk, int $chunkX, int $chunkZ): void {
        $biomes = $this->biomesConfig->get("biomes", []);
        
        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                $globalX = $chunkX * 16 + $x;
                $globalZ = $chunkZ * 16 + $z;

                $biome = $this->getBiomeAt($globalX, $globalZ);
                $biomeConfig = $biomes[$biome] ?? $biomes['plains'];
                
                $this->applyBiomeBlocks($chunk, $x, $z, $biomeConfig);
                
                $chunk->setBiomeId($x, $z, $this->getBiomeId($biome));
            }
        }
    }
    
    private function getBiomeAt(int $x, int $z): string {
        $cacheKey = $x . ":" . $z;
        
        if (isset($this->biomeCache[$cacheKey])) {
            return $this->biomeCache[$cacheKey];
        }
        
        $biomeValue = $this->biomeNoise->getValue($x, $z);
        
        $temperature = $this->biomeNoise->getTemperature($x, $z);
        $humidity = $this->biomeNoise->getHumidity($x, $z);
        
        $biome = $this->selectBiomeFromClimate($temperature, $humidity);
        
        $this->biomeCache[$cacheKey] = $biome;
        return $biome;
    }
    
    private function selectBiomeFromClimate(float $temperature, float $humidity): string {
        if ($temperature > 0.8) {
            if ($humidity < 0.3) return "desert";
            if ($humidity < 0.6) return "plains";
            return "forest";
        } elseif ($temperature > 0.5) {
            if ($humidity < 0.4) return "plains";
            return "forest";
        } else {
            if ($humidity < 0.5) return "mountains";
            return "ocean";
        }
    }
    
    private function applyBiomeBlocks(Chunk $chunk, int $x, int $z, array $biomeConfig): void {
        $surfaceY = $this->findSurface($chunk, $x, $z);
        
        if ($surfaceY === -1) return;
        
        $surfaceBlock = $this->getBlockIdFromString($biomeConfig['blocks']['surface'] ?? "grass");
        $subsurfaceBlock = $this->getBlockIdFromString($biomeConfig['blocks']['subsurface'] ?? "dirt");
        
        $chunk->setBlockStateId($x, $surfaceY, $z, $surfaceBlock);
        $chunk->setBlockStateId($x, $surfaceY - 1, $z, $subsurfaceBlock);
    }
    
    private function generateCaves(Chunk $chunk, int $chunkX, int $chunkZ): void {
        $threshold = $this->mainConfig->getNested("world-generator.algorithms.cave.threshold", 0.4);
        
        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                for ($y = 1; $y < 128; $y++) {
                    $globalX = $chunkX * 16 + $x;
                    $globalZ = $chunkZ * 16 + $z;
                    
                    $caveNoise = $this->caveNoise->noise3D($globalX, $y, $globalZ, true);
                    
                    if ($caveNoise > $threshold) {
                        $chunk->setBlockStateId($x, $y, $z, Block::AIR);
                    }
                }
            }
        }
    }
    
    private function generateStructures(Chunk $chunk, int $chunkX, int $chunkZ): void {
        if (!$this->mainConfig->getNested("world-generator.structures.enabled", true)) {
            return;
        }
        
        $structures = $this->structuresConfig->get("structures", []);
        
        foreach ($structures as $structureName => $structureConfig) {
            if (!$structureConfig['enabled']) continue;
            if (lcg_value() > $structureConfig['rarity']) continue;
            
            $this->generateStructure($chunk, $chunkX, $chunkZ, $structureName, $structureConfig);
        }
    }
    
    private function generateStructure(Chunk $chunk, int $chunkX, int $chunkZ, string $structureName, array $config): void {
        $x = mt_rand(0, 15);
        $z = mt_rand(0, 15);
        $y = $this->findSurface($chunk, $x, $z);
        
        if ($y === -1) return;
        
        switch ($structureName) {
            case "village":
                $this->generateVillage($chunk, $x, $y, $z, $config);
                break;
            case "dungeon":
                $this->generateDungeon($chunk, $x, $y, $z, $config);
                break;
            case "ruins":
                $this->generateRuins($chunk, $x, $y, $z, $config);
                break;
        }
    }
    
    private function generateVillage(Chunk $chunk, int $centerX, int $centerY, int $centerZ, array $config): void {
        $size = mt_rand($config['min-size'], $config['max-size']);
        
        for ($i = 0; $i < $size; $i++) {
            $buildingType = $this->selectBuilding($config['buildings']);
            $this->generateBuilding($chunk, $centerX, $centerY, $centerZ, $buildingType);
        }
    }
    
    private function generateDungeon(Chunk $chunk, int $x, int $y, int $z, array $config): void {
        $roomCount = mt_rand($config['rooms']['min'], $config['rooms']['max']);
        
        $this->generateDungeonRoom($chunk, $x, $y, $z, 5, 5, 3);
        
        for ($i = 1; $i < $roomCount; $i++) {
            $dirX = mt_rand(-1, 1);
            $dirZ = mt_rand(-1, 1);
            $newX = $x + $dirX * 8;
            $newZ = $z + $dirZ * 8;
            
            $this->generateDungeonRoom($chunk, $newX, $y, $newZ, 4, 4, 3);
            $this->generateDungeonCorridor($chunk, $x, $y, $z, $newX, $newZ);
        }
    }
    
    private function decorateTerrain(Chunk $chunk, int $chunkX, int $chunkZ): void {
        $biomes = $this->biomesConfig->get("biomes", []);
        
        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                $globalX = $chunkX * 16 + $x;
                $globalZ = $chunkZ * 16 + $z;
                
                $biome = $this->getBiomeAt($globalX, $globalZ);
                $biomeConfig = $biomes[$biome] ?? [];
                
                $this->placeDecorations($chunk, $x, $z, $biomeConfig);
            }
        }
    }
    
    private function placeDecorations(Chunk $chunk, int $x, int $z, array $biomeConfig): void {
        $decorations = $biomeConfig['decorations'] ?? [];
        
        foreach ($decorations as $decoration) {
            if (lcg_value() < $decoration['density']) {
                $y = $this->findSurface($chunk, $x, $z);
                if ($y !== -1) {
                    $this->placeDecoration($chunk, $x, $y, $z, $decoration);
                }
            }
        }
    }
    
    private function findSurface(Chunk $chunk, int $x, int $z): int {
        for ($y = 127; $y >= 0; $y--) {
            $blockId = $chunk->getBlockStateId($x, $y, $z);
            if ($blockId !== Block::AIR) {
                return $y;
            }
        }
        return -1;
    }
    
    private function getBlockIdFromString(string $blockName): int {
        $blockMap = [
            "grass" => Block::GRASS,
            "dirt" => Block::DIRT,
            "stone" => Block::STONE,
            "sand" => Block::SAND,
            "water" => Block::WATER,
            "bedrock" => Block::BEDROCK,
            "sandstone" => Block::SANDSTONE,
            "oak_log" => Block::OAK_LOG,
            "oak_leaves" => Block::OAK_LEAVES,
            "birch_log" => Block::BIRCH_LOG,
            "birch_leaves" => Block::BIRCH_LEAVES,
            "cactus" => Block::CACTUS,
            "coal_ore" => Block::COAL_ORE
        ];
        
        return $blockMap[$blockName] ?? Block::STONE;
    }
    
    private function getBiomeId(string $biomeName): int {
        $biomeMap = [
            "plains" => 1,
            "forest" => 4,
            "desert" => 2,
            "mountains" => 3,
            "ocean" => 0
        ];
        
        return $biomeMap[$biomeName] ?? 1;
    }
    
    public function getSpawn(): Vector3 {
        return new Vector3(0, 128, 0);
    }
    
    public function clearCache(): void {
        $this->noiseCache = [];
        $this->biomeCache = [];
    }
}