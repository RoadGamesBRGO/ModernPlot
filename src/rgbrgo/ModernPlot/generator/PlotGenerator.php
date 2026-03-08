<?php
declare(strict_types=1);

namespace rgbrgo\ModernPlot\generator;

use pocketmine\block\VanillaBlocks;
use pocketmine\world\ChunkManager;
use pocketmine\world\generator\Generator;

class PlotGenerator extends Generator {
    public int $plotSize = 42;
    public int $roadWidth = 7;

    public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ): void {
        $chunk = $world->getChunk($chunkX, $chunkZ);
        $total = $this->plotSize + $this->roadWidth;

        $bedrock = VanillaBlocks::BEDROCK()->getStateId();
        $dirt = VanillaBlocks::DIRT()->getStateId();
        $grass = VanillaBlocks::GRASS()->getStateId();
        $wood = VanillaBlocks::OAK_PLANKS()->getStateId();
        $slab = VanillaBlocks::STONE_SLAB()->getStateId();
        
        $wall = VanillaBlocks::COBBLESTONE_WALL()->getStateId();
        $fence = VanillaBlocks::DARK_OAK_FENCE()->getStateId();
        $lamp = VanillaBlocks::SEA_LANTERN()->getStateId();

        for ($x = 0; $x < 16; $x++) {
            for ($z = 0; $z < 16; $z++) {
                $worldX = ($chunkX << 4) + $x;
                $worldZ = ($chunkZ << 4) + $z;

                $relX = $worldX % $total;
                $relZ = $worldZ % $total;
                if ($relX < 0) $relX += $total;
                if ($relZ < 0) $relZ += $total;

                $chunk->setBlockStateId($x, 0, $z, $bedrock);
                for ($y = 1; $y < 64; $y++) $chunk->setBlockStateId($x, $y, $z, $dirt);

                if ($relX < $this->roadWidth || $relZ < $this->roadWidth) {
                    $chunk->setBlockStateId($x, 64, $z, $wood);
                } else {
                    $chunk->setBlockStateId($x, 64, $z, $grass);

                    // Bordas do 42x42
                    $isMinX = ($relX === $this->roadWidth);
                    $isMaxX = ($relX === ($total - 1));
                    $isMinZ = ($relZ === $this->roadWidth);
                    $isMaxZ = ($relZ === ($total - 1));

                    $isCorner = (($isMinX || $isMaxX) && ($isMinZ || $isMaxZ));

                    // Coloca laje apenas se não for esquina
                    if (($isMinX || $isMaxX || $isMinZ || $isMaxZ) && !$isCorner) {
                        $chunk->setBlockStateId($x, 65, $z, $slab);
                    }

                    // Postes nos cantos - Começam na camada 65 (Nível do chão)
                    if ($isCorner) {
                        $chunk->setBlockStateId($x, 65, $z, $wall);
                        $chunk->setBlockStateId($x, 66, $z, $fence);
                        $chunk->setBlockStateId($x, 67, $z, $fence);
                        $chunk->setBlockStateId($x, 68, $z, $lamp);
                    }
                }
            }
        }
    }

    public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ): void {}
}