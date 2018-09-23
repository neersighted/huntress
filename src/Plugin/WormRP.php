<?php

/*
 * Copyright (c) 2018 Keira Aro <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class WormRP implements \Huntress\PluginInterface
{
    use \Huntress\PluginHelperTrait;

    public static function register(\Huntress\Bot $bot)
    {
        $bot->client->on(self::PLUGINEVENT_DB_SCHEMA, [self::class, "db"]);
        $bot->client->on(self::PLUGINEVENT_READY, [self::class, "poll"]);
    }

    public static function db(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $t = $schema->createTable("wormrp_config");
        $t->addColumn("key", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->addColumn("value", "text", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t->setPrimaryKey(["key"]);

        $t2 = $schema->createTable("wormrp_users");
        $t2->addColumn("user", "bigint", ["unsigned" => true]);
        $t2->addColumn("redditName", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t2->setPrimaryKey(["user"]);
        $t2->addIndex(["redditName"]);

        $t3 = $schema->createTable("wormrp_activity");
        $t3->addColumn("redditName", "string", ['customSchemaOptions' => \Huntress\DatabaseFactory::CHARSET]);
        $t3->addColumn("lastSubActivity", "datetime");
        $t3->setPrimaryKey(["redditName"]);
    }

    /**
     * Adapted from Ligrev code by Christoph Burschka <christoph@burschka.de>
     */
    public static function poll(\Huntress\Bot $bot)
    {
        $bot->loop->addPeriodicTimer(60, function() use ($bot) {
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://www.reddit.com/r/wormrp/new.json")->then(function(string $string) use ($bot) {
                $items    = json_decode($string)->data->children;
                $lastPub  = self::getLastRSS();
                $newest   = $lastPub;
                $newItems = [];
                foreach ($items as $item) {
                    $published  = (int) $item->data->created_utc;
                    if ($published <= $lastPub || is_null($item->data->link_flair_text))
                        continue;
                    $newest     = max($newest, $published);
                    $newItems[] = (object) [
                        'title'    => $item->data->title,
                        'link'     => "https://reddit.com" . $item->data->permalink,
                        'date'     => \Carbon\Carbon::createFromTimestamp($item->data->created_utc),
                        'category' => $item->data->link_flair_text,
                        'body'     => (strlen($item->data->selftext) > 0) ? $item->data->selftext : $item->data->url,
                        'author'   => $item->data->author,
                    ];
                }
                foreach ($newItems as $item) {
                    if (mb_strlen($item->body) > 512) {
                        $item->body = substr($item->body, 0, 509) . "...";
                    }
                    switch ($item->category) {
                        case "Character":
                        case "Equipment":
                        case "Lore":
                        case "Group":
                            $channel = "386943351062265888"; // the_list
                            break;
                        case "Meta":
                            $channel = "118981144464195584"; // general
                            break;
                        case "Event":
                        case "Patrol":
                        case "Non-Canon":
                        default:
                            $channel = "409043591881687041"; // events
                    }
                    $embed = new \CharlotteDunois\Yasmin\Models\MessageEmbed();
                    $embed->setTitle($item->title)->setURL($item->link)->setDescription($item->body)->setTimestamp($item->date->timestamp)->setFooter($item->category)->setAuthor($item->author);
                    $bot->client->channels->get($channel)->send("", ['embed' => $embed]);
                }
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO wormrp_config (`key`, `value`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);', ['string', 'integer']);
                $query->bindValue(1, "rssPublished");
                $query->bindValue(2, $newest);
                $query->execute();
            });
        });
        $bot->loop->addPeriodicTimer(300, function() use ($bot) {
            return \CharlotteDunois\Yasmin\Utils\URLHelpers::resolveURLToData("https://www.reddit.com/r/wormrp/comments.json")->then(function(string $string) {
                $items = json_decode($string)->data->children;
                $users = [];
                foreach ($items as $item) {
                    $published                  = $item->data->created_utc;
                    $users[$item->data->author] = max($published, $users[$item->data->author] ?? 0);
                }
                $query = \Huntress\DatabaseFactory::get()->prepare('INSERT INTO wormrp_activity (`redditName`, `lastSubActivity`) VALUES(?, ?) '
                . 'ON DUPLICATE KEY UPDATE `lastSubActivity`=VALUES(`lastSubActivity`);', ['string', 'datetime']);
                foreach ($users as $name => $date) {
                    $query->bindValue(1, $name);
                    $query->bindValue(2, \Carbon\Carbon::createFromTimestamp($date));
                    $query->execute();
                }
            });
        });
    }

    private static function getLastRSS(): int
    {
        $qb  = \Huntress\DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("wormrp_config")->where('`key` = ?')->setParameter(0, 'rssPublished', "string");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return $data['value'];
        }
        return 0;
    }
}
