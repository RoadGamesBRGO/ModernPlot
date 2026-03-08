<?php
declare(strict_types=1);

namespace rgbrgo\ModernPlot\forms;

use pocketmine\player\Player;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use rgbrgo\ModernPlot\Main;

class FormManager {

    public static function enviarMenuPrincipal(Player $player): void {
        $data = [
            "type" => "buttons",
            "title" => "§l§bMODERN PLOT",
            "content" => "Olá, " . $player->getName() . "!\nGerencie seu terreno:",
            "buttons" => [
                ["text" => "§aComprar Terreno\n§8Clique para dominar"],
                ["text" => "§6Ir para minha Plot\n§8Teleporte rápido"],
                ["text" => "§cFechar\n§8Sair do menu"]
            ]
        ];
        $player->getNetworkSession()->sendDataPacket(ModalFormRequestPacket::create(100, json_encode($data)));
    }
}