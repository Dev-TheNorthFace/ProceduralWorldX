<?php

declare(strict_types=1);

namespace North\ProceduralWorldX\PerlinNoise;

class PerlinNoise {
    
    private int $seed;
    private int $octaves;
    private float $persistence;
    private float $scale;
    
    public function __construct(int $seed, int $octaves = 4, float $persistence = 0.5, float $scale = 0.01) {
        $this->seed = $seed;
        $this->octaves = $octaves;
        $this->persistence = $persistence;
        $this->scale = $scale;
    }
    
    public function noise2D(float $x, float $z, bool $normalized = false): float {
        $total = 0.0;
        $frequency = $this->scale;
        $amplitude = 1.0;
        $maxValue = 0.0;
        
        for ($i = 0; $i < $this->octaves; $i++) {
            $total += $this->perlin2D($x * $frequency, $z * $frequency) * $amplitude;
            $maxValue += $amplitude;
            $amplitude *= $this->persistence;
            $frequency *= 2;
        }
        
        if ($normalized) {
            return $total / $maxValue;
        }
        
        return $total;
    }
    
    public function noise3D(float $x, float $y, float $z, bool $normalized = false): float {
        $total = 0.0;
        $frequency = $this->scale;
        $amplitude = 1.0;
        $maxValue = 0.0;
        
        for ($i = 0; $i < $this->octaves; $i++) {
            $total += $this->perlin3D($x * $frequency, $y * $frequency, $z * $frequency) * $amplitude;
            $maxValue += $amplitude;
            $amplitude *= $this->persistence;
            $frequency *= 2;
        }
        
        if ($normalized) {
            return $total / $maxValue;
        }
        
        return $total;
    }
    
    private function perlin2D(float $x, float $z): float {
        $X = (int)floor($x) & 255;
        $Z = (int)floor($z) & 255;
        
        $x -= floor($x);
        $z -= floor($z);
        
        $u = $this->fade($x);
        $v = $this->fade($z);
        
        $aaa = $this->p[$this->p[$X] + $Z];
        $aba = $this->p[$this->p[$X] + $Z + 1];
        $aab = $this->p[$this->p[$X + 1] + $Z];
        $abb = $this->p[$this->p[$X + 1] + $Z + 1];
        
        $x1 = $this->lerp($u, $this->grad($aaa, $x, $z, 0), $this->grad($aab, $x - 1, $z, 0));
        $x2 = $this->lerp($u, $this->grad($aba, $x, $z - 1, 0), $this->grad($abb, $x - 1, $z - 1, 0));
        
        return $this->lerp($v, $x1, $x2);
    }
    
    private function perlin3D(float $x, float $y, float $z): float {
        $X = (int)floor($x) & 255;
        $Y = (int)floor($y) & 255;
        $Z = (int)floor($z) & 255;
        
        $x -= floor($x);
        $y -= floor($y);
        $z -= floor($z);
        
        $u = $this->fade($x);
        $v = $this->fade($y);
        $w = $this->fade($z);
        
        return 0.0;
    }
    
    private function fade(float $t): float {
        return $t * $t * $t * ($t * ($t * 6 - 15) + 10);
    }
    
    private function lerp(float $t, float $a, float $b): float {
        return $a + $t * ($b - $a);
    }
    
    private function grad(int $hash, float $x, float $y, float $z): float {
        $h = $hash & 15;
        $u = $h < 8 ? $x : $y;
        $v = $h < 4 ? $y : ($h == 12 || $h == 14 ? $x : $z);
        return (($h & 1) == 0 ? $u : -$u) + (($h & 2) == 0 ? $v : -$v);
    }
    
    private array $p = [];
    
    public function __construct(int $seed, int $octaves = 4, float $persistence = 0.5, float $scale = 0.01) {
        $this->initializePermutationTable($seed);
    }
    
    private function initializePermutationTable(int $seed): void {
        mt_srand($seed);
        $this->p = array_fill(0, 512, 0);
        
        for ($i = 0; $i < 256; $i++) {
            $this->p[$i] = $this->p[256 + $i] = mt_rand(0, 255);
        }
    }
}

class VoronoiNoise {
    
    private int $seed;
    private int $points;
    private float $scale;
    private array $featurePoints = [];
    
    public function __construct(int $seed, int $points = 50, float $scale = 0.005) {
        $this->seed = $seed;
        $this->points = $points;
        $this->scale = $scale;
        $this->generateFeaturePoints();
    }
    
    private function generateFeaturePoints(): void {
        mt_srand($this->seed);
        
        for ($i = 0; $i < $this->points; $i++) {
            $this->featurePoints[] = [
                'x' => mt_rand() / mt_getrandmax() * 1000,
                'z' => mt_rand() / mt_getrandmax() * 1000,
                'biome' => $this->getRandomBiomeType()
            ];
        }
    }
    
    public function getValue(float $x, float $z): array {
        $scaledX = $x * $this->scale;
        $scaledZ = $z * $this->scale;
        
        $closestDistance = PHP_FLOAT_MAX;
        $closestPoint = null;
        
        foreach ($this->featurePoints as $point) {
            $dx = $scaledX - $point['x'];
            $dz = $scaledZ - $point['z'];
            $distance = $dx * $dx + $dz * $dz;
            
            if ($distance < $closestDistance) {
                $closestDistance = $distance;
                $closestPoint = $point;
            }
        }
        
        return [
            'biome' => $closestPoint['biome'] ?? 'plains',
            'distance' => sqrt($closestDistance)
        ];
    }
    
    public function getTemperature(float $x, float $z): float {
        $value = $this->getValue($x, $z);
        return (sin($x * 0.01) + 1) * 0.5;
    }
    
    public function getHumidity(float $x, float $z): float {
        $value = $this->getValue($x, $z);
        return (cos($z * 0.008) + 1) * 0.5;
    }
    
    private function getRandomBiomeType(): string {
        $biomes = ['plains', 'forest', 'desert', 'mountains', 'ocean'];
        return $biomes[array_rand($biomes)];
    }
}