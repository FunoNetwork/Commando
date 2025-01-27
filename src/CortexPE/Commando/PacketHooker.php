<?php

/***
 *    ___                                          _
 *   / __\___  _ __ ___  _ __ ___   __ _ _ __   __| | ___
 *  / /  / _ \| '_ ` _ \| '_ ` _ \ / _` | '_ \ / _` |/ _ \
 * / /__| (_) | | | | | | | | | | | (_| | | | | (_| | (_) |
 * \____/\___/|_| |_| |_|_| |_| |_|\__,_|_| |_|\__,_|\___/
 *
 * Commando - A Command Framework virion for PocketMine-MP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Written by @CortexPE <https://CortexPE.xyz>
 *
 */
declare(strict_types=1);

namespace CortexPE\Commando;


use CortexPE\Commando\exception\HookAlreadyRegistered;
use CortexPE\Commando\store\SoftEnumStore;
use CortexPE\Commando\traits\IArgumentable;
use pocketmine\command\CommandMap;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use function array_unique;
use function array_unshift;
use function array_values;

class PacketHooker implements Listener {
	/** @var bool */
	private static $isRegistered = false;
	/** @var CommandMap */
	protected $map;

	public function __construct() {
		$this->map = Server::getInstance()->getCommandMap();
	}

	public static function isRegistered(): bool {
		return self::$isRegistered;
	}

	public static function register(Plugin $registrant): void {
		if(self::$isRegistered) {
			throw new HookAlreadyRegistered("Event listener is already registered by another plugin.");
		}
		$registrant->getServer()->getPluginManager()->registerEvents(new PacketHooker(), $registrant);
	}

	/**
	 * @param DataPacketSendEvent $ev
	 *
	 * @priority        LOWEST
	 * @ignoreCancelled true
	 */
	public function onPacketSend(DataPacketSendEvent $ev): void {
		foreach($ev->getPackets() as $pk) {
			if($pk instanceof AvailableCommandsPacket) {
				foreach($ev->getTargets() as $target) {
					foreach($pk->commandData as $commandName => $commandData) {
						$cmd = $this->map->getCommand($commandName);
						if($cmd instanceof BaseCommand) {
							$pk->commandData[$commandName]->overloads = self::generateOverloads($target->getPlayer(), $cmd);
						}
					}
				}
				$pk->softEnums = SoftEnumStore::getEnums();
			}
		}
	}

	/**
	 * @param CommandSender $cs
	 * @param BaseCommand $command
	 *
	 * @return CommandParameter[][]
	 */
	private static function generateOverloads(CommandSender $cs, BaseCommand $command): array {
		$overloads = [];

		/** @var BaseSubCommand[] $subCommands */
		$subCommands = array_values(array_unique($command->getSubCommands(), SORT_REGULAR));
		foreach($subCommands as $sI => $subCommand) {
			if(!$subCommand->testPermissionSilent($cs)){
				continue;
			}
			$scParam = new CommandParameter();
			$scParam->paramName = $subCommand->getName();
			$scParam->isOptional = true;
			$scParam->paramType = AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_STRING;

			// it looks uglier imho
			//$scParam->enum = $scEnum;

			$overloadList = self::generateOverloadList($subCommand);
			if(!empty($overloadList)){
				foreach($overloadList as $overload) {
					array_unshift($overload, $scParam);
					$overloads[] = $overload;
				}
			} else {
				$overloads[] = [$scParam];
			}
		}

		foreach(self::generateOverloadList($command) as $overload) {
			$overloads[] = $overload;
		}

		return $overloads;
	}

	/**
	 * @param IArgumentable $argumentable
	 *
	 * @return CommandParameter[][]
	 */
	private static function generateOverloadList(IArgumentable $argumentable): array {
		$params = [];
		foreach($argumentable->getArgumentList() as $pos => $converters) {
			foreach($converters as $i => $argument) {
				$params[$i][$pos] = $argument->getNetworkParameterData();
			}
		}

		return $params;
	}
}