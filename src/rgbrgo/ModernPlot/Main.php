<?php
declare(strict_types=1);

namespace rgbrgo\ModernPlot;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{Command, CommandSender};
use pocketmine\player\Player;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use rgbrgo\ModernPlot\generator\PlotGenerator;
use rgbrgo\ModernPlot\database\PlotDatabase;
// O "use" abaixo pode causar erro no Poggit se o outro plugin não for uma lib, 
// por isso usamos o ignore ou chamamos pelo caminho completo.
use rgbrgo\ModernEconomy\Main as Economy; 

class Main extends PluginBase {
    private static self $instance;
    private PlotDatabase $database;
    
    public int $plotSize = 42;
    public int $roadWidth = 7;

    protected function onEnable(): void {
        self::$instance = $this;
        if(!is_dir($this->getDataFolder())) @mkdir($this->getDataFolder());
        
        $this->saveDefaultConfig(); 
        
        $this->database = new PlotDatabase();
        
        // Registro do Gerador
        GeneratorManager::getInstance()->addGenerator(PlotGenerator::class, "modernplot", fn() => null);
        
        // Registro de Eventos
        $this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
    }

    public static function getInstance(): self { return self::$instance; }
    public function getDatabase(): PlotDatabase { return $this->database; }

    public function isInsidePlot(Vector3 $pos): bool {
        $total = 49;
        $relX = (int)floor($pos->getX()) % $total;
        $relZ = (int)floor($pos->getZ()) % $total;
        if($relX < 0) $relX += $total;
        if($relZ < 0) $relZ += $total;

        return ($relX > 7 && $relX < 48 && $relZ > 7 && $relZ < 48);
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($sender instanceof Player && $command->getName() === "plot") {
            $this->menuPrincipal($sender);
            return true;
        }
        return false;
    }

    public function menuPrincipal(Player $player): void {
        $config = $this->getConfig();
        $priceBuy = $config->getNested("precos.compra", 1000.0);
        $priceClear = $config->getNested("precos.limpeza", 200000.0);
        $priceSell = $config->getNested("precos.desistir", 200000.0);

        $buttons = [
            ["text" => "§l§0COMPRAR TERRENO\n§8Preço: $ " . number_format($priceBuy, 2), "image" => ["type" => "path", "data" => "textures/ui/shopping_cart"]],
            ["text" => "§l§0AMIGOS\n§8Gerenciar ajudantes", "image" => ["type" => "path", "data" => "textures/ui/multiplayer_glyph_color"]],
            ["text" => "§l§0LIMPAR PLOT\n§8Custo: $ " . number_format($priceClear, 2), "image" => ["type" => "path", "data" => "textures/ui/refresh_light"]],
            ["text" => "§l§cDESISTIR\n§8Custo: $ " . number_format($priceSell, 2), "image" => ["type" => "path", "data" => "textures/ui/cancel"]]
        ];

        if ($this->getServer()->isOp($player->getName())) {
            $buttons[] = ["text" => "§l§dCRIAR MUNDO (ADM)\n§8Gerar novo mapa"];
        }

        $data = [
            "type" => "form",
            "title" => "§l§bMODERN PLOT",
            "content" => "§7Gerencie o seu terreno atual:",
            "buttons" => $buttons
        ];
        
        $pk = \pocketmine\network\mcpe\protocol\ModalFormRequestPacket::create(100, json_encode($data));
        $player->getNetworkSession()->sendDataPacket($pk);
    }

    public function claimProcess(Player $player): void {
        if (!$this->isInsidePlot($player->getPosition())) {
            $player->sendMessage("§c§l[!]§r§c Você não pode comprar um terreno estando na rua ou na calçada!");
            return;
        }

        $world = $player->getWorld();
        $economyPlugin = $this->getServer()->getPluginManager()->getPlugin("ModernEconomy");
        $priceBuy = (float) $this->getConfig()->getNested("precos.compra", 1000.0);

        // O comentário abaixo ignora o erro de "Classe não encontrada" no Poggit
        /** @var \rgbrgo\ModernEconomy\Main $economyPlugin */
        if ($economyPlugin instanceof \pocketmine\plugin\Plugin) { 
            $total = 49;
            $pos = $player->getPosition();
            $pX = (int) floor($pos->getX() / $total);
            $pZ = (int) floor($pos->getZ() / $total);

            $owner = $this->database->getOwner($pX, $pZ, $world->getFolderName());
            if ($owner === null) {
                // Ignore para o Poggit passar pelo reduceMoney
                if ($economyPlugin->reduceMoney($player->getName(), $priceBuy)) { /** @phpstan-ignore-line */
                    $this->database->claimPlot($pX, $pZ, $player->getName(), $world->getFolderName());
                    $player->sendMessage("§a§l[!]§r§a Terreno comprado por §f$ " . number_format($priceBuy, 2));
                } else {
                    $player->sendMessage("§c§l[!]§r§c Saldo insuficiente! Você precisa de §f$ " . number_format($priceBuy, 2));
                }
            } else {
                $player->sendMessage("§cEste terreno ja tem dono!");
            }
        }
    }

    public function clearPlot(Player $player, int $pX, int $pZ, bool $charge = true): void {
        $world = $player->getWorld();
        
        if($charge){
            $priceClear = (float) $this->getConfig()->getNested("precos.limpeza", 200000.0);
            $eco = $this->getServer()->getPluginManager()->getPlugin("ModernEconomy");
            /** @var \rgbrgo\ModernEconomy\Main $eco */
            if ($eco instanceof \pocketmine\plugin\Plugin) {
                if (!$eco->reduceMoney($player->getName(), $priceClear)) { /** @phpstan-ignore-line */
                    $player->sendMessage("§c§l[!]§r§c Você precisa de $ " . number_format($priceClear, 2) . " para limpar o terreno.");
                    return;
                }
            }
        }

        $total = $this->plotSize + $this->roadWidth;
        $startX = ($pX * $total) + $this->roadWidth; 
        $startZ = ($pZ * $total) + $this->roadWidth;
        $endX = $startX + $this->plotSize - 1;
        $endZ = $startZ + $this->plotSize - 1;

        for($x = $startX; $x <= $endX; $x++) {
            for($z = $startZ; $z <= $endZ; $z++) {
                for($y = 65; $y <= 100; $y++) {
                    $world->setBlock(new Vector3($x, $y, $z), VanillaBlocks::AIR());
                }
                $world->setBlock(new Vector3($x, 64, $z), VanillaBlocks::GRASS());
            }
        }

        $player->teleport(new Vector3(($pX * $total) + 15, 66, ($pZ * $total) + 15));
        $player->sendMessage("§a§l[!]§r§a Terreno limpo!");
    }

    public function menuAmigos(Player $player): void {
        $total = 49;
        $pos = $player->getPosition();
        $pX = (int) floor($pos->getX() / $total);
        $pZ = (int) floor($pos->getZ() / $total);
        $worldName = $player->getWorld()->getFolderName();
        $priceFriend = $this->getConfig()->getNested("precos.amigo", 500.0);

        $friends = $this->database->getFriends($pX, $pZ, $worldName);
        $count = count($friends);
        
        $data = [
            "type" => "form",
            "title" => "§l§9GERENCIAR AMIGOS",
            "content" => "§7Amigos adicionados: §f$count/5\n§7Custo para adicionar: §a$ " . number_format($priceFriend, 2),
            "buttons" => [
                ["text" => "§l§2ADICIONAR§r\n§8Novo ajudante", "image" => ["type" => "path", "data" => "textures/ui/color_plus"]],
                ["text" => "§l§4REMOVER TUDO§r\n§8Limpar lista", "image" => ["type" => "path", "data" => "textures/ui/cancel"]],
                ["text" => "§l§8VOLTAR", "image" => ["type" => "path", "data" => "textures/ui/back_button"]]
            ]
        ];
        $pk = \pocketmine\network\mcpe\protocol\ModalFormRequestPacket::create(105, json_encode($data));
        $player->getNetworkSession()->sendDataPacket($pk);
    }
}
