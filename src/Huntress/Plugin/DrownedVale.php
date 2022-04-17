<?php

/*
 * Copyright (c) 2022 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use CharlotteDunois\Yasmin\Models\GuildMember;
use Huntress\EventData;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\Permission;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\all;

class DrownedVale implements PluginInterface
{
    use PluginHelperTrait;

    public const ROLE_RECRUIT = 944096516593831947;
    public const ROLE_MEMBER = 943996715160182844;
    public const ROLE_TENURED = 943653875368480808;
    public const ROLE_COMPOSITE_DVI = 958134803306274846;

    public const ROLE_VC_LOW = 965150197598523402;
    public const ROLE_VC_HIGH = 965151757657325608;

    public const CHCAT_LOW = 943996180839419946;
    public const CHCAT_HIGH = 943996278310834207;

    public const CH_LOG = 943655113854185583;
    public const GUILD = 943653352305209406;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([self::class, "pollActiveCheck"])->setPeriodic(10)
        );
        $bot->eventManager->addEventListener(
            EventListener::new()->setCallback([self::class, "dviVCAccess"])
                ->addEvent("voiceStateUpdate")->addGuild(self::GUILD)
        );
        $bot->eventManager->addEventListener(
            EventListener::new()
                ->addCommand("vc")
                ->addCommand("vcr")
                ->addGuild(self::GUILD)
                ->setCallback([self::class, "dviVCRename"])
        );
    }

    public static function pollActiveCheck(Huntress $bot): ?PromiseInterface
    {
        try {
            $currDVI = $bot->guilds->get(self::GUILD)->members->filter(function ($v, $k) {
                return $v->roles->has(self::ROLE_COMPOSITE_DVI);
            });
            $targetRoles = [
                self::ROLE_MEMBER,
                self::ROLE_RECRUIT,
                self::ROLE_TENURED
            ];

            $x = [];
            /** @var GuildMember $member */
            foreach ($currDVI as $member) {
                $shouldHave = false;
                foreach ($targetRoles as $trID) {
                    if ($member->roles->has($trID)) {
                        $shouldHave = true;
                    }
                }

                if (!$shouldHave) {
                    $x[] = $member->removeRole(self::ROLE_COMPOSITE_DVI);
                    $x[] = $bot->channels->get(self::CH_LOG)->send(
                        sprintf("[DrownedVale] Removed <@%s> from DVI composite role.", $member->id)
                    );
                }
            }
            return all($x);
        } catch (Throwable $e) {
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
            return null;
        }
    }

    public static function dviVCRename(EventData $data): ?PromiseInterface
    {
        if (is_null($data->message->member->voiceChannel) ||
            !$data->guild->channels->has($data->message->member->voiceChannel->id)
        ) {
            return $data->message->reply("You must be in a voice channel to use this command");
        }

        $p = new Permission("p.dvi.vc.rename", $data->huntress, true);
        $p->addMessageContext($data->message);
        if (!$p->resolve()) {
            return $p->sendUnauthorizedMessage($data->message->channel);
        }

        if ($name = self::arg_substr($data->message->content, 1) ?? false) {
            if (mb_strlen($name) > 100) { // discord limit
                return $data->message->reply("Voice channel name must be less than 100 chars.");
            }
            // todo: further filtering to ensure the channel name will pass muster; for now we'll just shit ourselves if discord throws an error.
            return $data->message->member->voiceChannel->setName($name, $data->message->getJumpURL())->then(
                function () use ($data) {
                    return $data->message->react("😤");
                },
                function () use ($data) {
                    return $data->message->reply("Discord rejected this name!");
                }
            );
        } else {
            return $data->message->reply("Usage: `!vc New Voice Channel Name`");
        }
    }

    public static function dviVCAccess(EventData $data): void
    {
        if ($data->user->voiceChannel?->parentID == self::CHCAT_LOW) {
            $data->user->addRole(self::ROLE_VC_LOW);
        } elseif ($data->user->roles->has(self::ROLE_VC_LOW)) {
            $data->user->removeRole(self::ROLE_VC_LOW);
        }

        if ($data->user->voiceChannel?->parentID == self::CHCAT_HIGH) {
            $data->user->addRole(self::ROLE_VC_HIGH);
        } elseif ($data->user->roles->has(self::ROLE_VC_HIGH)) {
            $data->user->removeRole(self::ROLE_VC_HIGH);
        }
    }

}
