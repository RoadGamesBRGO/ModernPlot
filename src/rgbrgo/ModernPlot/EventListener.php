<?php
declare(strict_types=1);

namespace rgbrgo\ModernPlot;

use pocketmine\event\Listener;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\block\{BlockBreakEvent, BlockPlaceEvent};

class EventListener implements Listener {

    // Armazena a última plot em que o jogador esteve para evitar spam de mensagens
    private array $lastPlot = [];

    public function __construct(private Main $plugin) {}

    /**
     * Mostra o nome do dono apenas quando estiver DENTRO do terreno (gramado)
     */
    public function onMove(PlayerMoveEvent $event): void {
        $player = $event->getPlayer();
        $pos = $player->getPosition();
        
        if ($pos->getWorld()->getFolderName() !== "plots") return;

        // CORREÇÃO: Se estiver na rua ou calçada, limpa o rastro e não mostra mensagem
        if (!$this->plugin->isInsidePlot($pos)) {
            unset($this->lastPlot[$player->getName()]);
            return;
        }

        $total = 49;
        $pX = (int)floor($pos->getX() / $total);
        $pZ = (int)floor($pos->getZ() / $total);
        $plotId = "$pX:$pZ";

        // Verifica se o jogador mudou de terreno
        if (!isset($this->lastPlot[$player->getName()]) || $this->lastPlot[$player->getName()] !== $plotId) {
            $this->lastPlot[$player->getName()] = $plotId;
            
            $owner = $this->plugin->getDatabase()->getOwner($pX, $pZ, $pos->getWorld()->getFolderName());
            
            if ($owner !== null) {
                $msg = str_replace("{owner}", $owner, $this->plugin->getConfig()->getNested("mensagens.entrou_terreno", "§e§lPLOT:§r §fTerreno de §b{owner}"));
                $player->sendActionBarMessage($msg);
            } else {
                $msg = $this->plugin->getConfig()->getNested("mensagens.terreno_livre", "§a§lPLOT:§r §7Terreno sem dono");
                $player->sendActionBarMessage($msg);
            }
        }
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $pk = $event->getPacket();
        if ($pk instanceof ModalFormResponsePacket) {
            $player = $event->getOrigin()->getPlayer();
            if ($player === null) return;

            if ($pk->formData === null) {
                if ($pk->formId === 105 || $pk->formId === 106) {
                    $this->plugin->menuPrincipal($player);
                }
                return;
            }
            
            $data = json_decode($pk->formData, true);
            if ($data === null) return; 

            $total = 49;
            $pos = $player->getPosition();
            $pX = (int)floor($pos->getX() / $total);
            $pZ = (int)floor($pos->getZ() / $total);
            $world = $player->getWorld()->getFolderName();

            if ($pk->formId === 100) {
                switch ($data) {
                    case 0: $this->plugin->claimProcess($player); break;
                    case 1: $this->plugin->menuAmigos($player); break;
                    case 2: 
                        $this->plugin->clearPlot($player, $pX, $pZ); 
                        break;
                    case 3: 
                        $owner = $this->plugin->getDatabase()->getOwner($pX, $pZ, $world);
                        if ($owner === $player->getName() || $player->getServer()->isOp($player->getName())) {
                            
                            $eco = $this->plugin->getServer()->getPluginManager()->getPlugin("ModernEconomy");
                            $precoDesistir = (float)$this->plugin->getConfig()->getNested("precos.desistir", 200000.0);
                            
                            if ($eco instanceof \rgbrgo\ModernEconomy\Main) {
                                if ($eco->reduceMoney($player->getName(), $precoDesistir)) {
                                    $this->plugin->clearPlot($player, $pX, $pZ, false);
                                    $this->plugin->getDatabase()->sellPlot($pX, $pZ, $world);
                                    $player->sendMessage("§e§l[!]§r§e Você desistiu do terreno. Custo: $ " . number_format($precoDesistir, 2));
                                } else {
                                    $player->sendMessage("§c§l[!]§r§c Você precisa de $ " . number_format($precoDesistir, 2) . " para desistir.");
                                }
                            }
                        }
                        break;
                }
            }

            if ($pk->formId === 105) {
                if ($data === 0) $this->plugin->addFriendUI($player);
                if ($data === 1) {
                    $this->plugin->getDatabase()->setFriends($pX, $pZ, $world, []);
                    $player->sendMessage("§a§l[!]§r§a Todos os amigos foram removidos.");
                    $this->plugin->menuAmigos($player);
                }
                if ($data === 2) {
                    $this->plugin->menuPrincipal($player);
                }
            }

            if ($pk->formId === 106) {
                if (!isset($data[1]) || trim($data[1]) === "") {
                    $this->plugin->menuAmigos($player);
                    return;
                }
                
                $target = strtolower(trim($data[1]));
                $friends = $this->plugin->getDatabase()->getFriends($pX, $pZ, $world);
                
                if (count($friends) >= 5) {
                    $player->sendMessage("§c§l[!]§r§c Limite de 5 amigos atingido!");
                    $this->plugin->menuAmigos($player);
                    return;
                }

                $eco = $this->plugin->getServer()->getPluginManager()->getPlugin("ModernEconomy");
                $precoAmigo = (float)$this->plugin->getConfig()->getNested("precos.amigo", 500.0);

                if ($eco instanceof \rgbrgo\ModernEconomy\Main) {
                    if ($eco->reduceMoney($player->getName(), $precoAmigo)) {
                        $friends[] = $target;
                        $this->plugin->getDatabase()->setFriends($pX, $pZ, $world, $friends);
                        $player->sendMessage("§a§l[!]§r§a Jogador §f$target §aadicionado por $ " . number_format($precoAmigo, 2));
                    } else {
                        $player->sendMessage("§c§l[!]§r§c Saldo insuficiente.");
                    }
                }
                $this->plugin->menuAmigos($player);
            }
        }
    }

    public function onBreak(BlockBreakEvent $e): void { $this->validar($e); }
    public function onPlace(BlockPlaceEvent $e): void { $this->validar($e); }

    private function validar($event): void {
        $player = $event->getPlayer();
        if ($player->getServer()->isOp($player->getName())) return;

        if ($event instanceof BlockPlaceEvent) {
            foreach ($event->getTransaction()->getBlocks() as [$x, $y, $z, $block]) {
                $pos = $block->getPosition();
                break; 
            }
        } else {
            $pos = $event->getBlock()->getPosition();
        }

        if (!isset($pos)) return;
        if ($pos->getWorld()->getFolderName() !== "plots") return;

        $total = 49;
        $relX = (int)floor($pos->getX()) % $total;
        $relZ = (int)floor($pos->getZ()) % $total;
        if($relX < 0) $relX += $total;
        if($relZ < 0) $relZ += $total;

        if ($relX <= 7 || $relZ <= 7 || $relX >= 48 || $relZ >= 48) {
            $event->cancel();
            return;
        }

        $pX = (int)floor($pos->getX() / $total);
        $pZ = (int)floor($pos->getZ() / $total);
        
        $db = $this->plugin->getDatabase();
        $owner = $db->getOwner($pX, $pZ, $pos->getWorld()->getFolderName());
        $friends = $db->getFriends($pX, $pZ, $pos->getWorld()->getFolderName());

        if ($owner !== null) {
            if ($owner === $player->getName() || in_array(strtolower($player->getName()), $friends)) {
                return;
            }
        }
        
        $event->cancel();
        $player->sendActionBarMessage("§c§lSEM PERMISSÃO");
    }
}