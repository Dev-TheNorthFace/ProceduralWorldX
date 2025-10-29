<?php

declare(strict_types=1);

namespace North\ProceduralWorldX\ProceduralWorld;

use pocketmine\plugin\PluginBase;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\WorldCreationOptions;

class ProceduralWorld extends PluginBase {
    
    public function onEnable(): void {
        GeneratorManager::getInstance()->addGenerator(
            AdvancedWorldGenerator::class,
            "procedural",
            fn() => null
        );
        
        $this->getServer()->getPluginManager()->registerEvents(new WorldListener($this), $this);
        $this->getServer()->getCommandMap()->register("world", new WorldCommand($this));
        
        $this->saveDefaultConfig();
        $this->saveResource("biomes.yml");
        $this->saveResource("structures.yml");
    }
    
    public function createProceduralWorld(string $worldName, string $seed = ""): bool {
        if ($this->getServer()->getWorldManager()->getWorldByName($worldName) !== null) {
            return false;
        }
        
        $seed = $seed !== "" ? crc32($seed) : mt_rand();
        
        $options = new WorldCreationOptions();
        $options->setGeneratorClass(AdvancedWorldGenerator::class);
        $options->setSeed($seed);
        
        return $this->getServer()->getWorldManager()->generateWorld($worldName, $options);
    }
}