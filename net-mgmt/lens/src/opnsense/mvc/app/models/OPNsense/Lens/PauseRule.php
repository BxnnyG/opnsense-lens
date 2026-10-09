<?php

/*
 * Copyright (C) 2026 Benny <claude@bxnny.de>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */
namespace OPNsense\Lens;

use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;
use OPNsense\Firewall\Category;
use OPNsense\Firewall\Filter;

/**
 * Class PauseRule
 *
 * The only code in Lens that writes the firewall's rules (§4.74): one MAC
 * alias and one floating block rule, through core's own Alias and Filter
 * models -- the classes behind Firewall: Aliases and Firewall: Rules -- so
 * both are in the GUI, the configuration history and every backup like
 * anything written by hand. What to write is decided by Pause; this class
 * only carries it into core's models and applies it.
 *
 * Verified on stable/26.1 and stable/26.7 (stage 49 plan §2). One ordering
 * matters: a rule naming an alias validates against the aliases already
 * saved (NetworkAliasField reads Alias::getCachedData(), keyed on the saved
 * configuration), so a new alias is saved before the rule that uses it is
 * created, and the Filter model is not loaded before that.
 *
 * @package OPNsense\Lens
 */
class PauseRule
{
    /**
     * What the firewall holds now.
     *
     * @return array ['content' => alias content or null, 'rule' => 'ok'|'disabled'|null]
     */
    public static function read(): array
    {
        $alias = self::alias(new Alias());
        $filter = new Filter();
        $uuid = Pause::findRule(self::rules($filter));
        $rule = null;
        if ($uuid !== null) {
            $rule = (string)$filter->rules->rule->$uuid->enabled === '1' ? 'ok' : 'disabled';
        }

        return ['content' => $alias === null ? null : (string)$alias->content, 'rule' => $rule];
    }

    /**
     * Add and remove MACs, creating the alias and the rule when a MAC is
     * added and they do not exist yet. Never re-enables a rule the operator
     * switched off, and never touches any other rule or alias.
     *
     * @param array $add MACs to put in the alias
     * @param array $remove MACs to take out
     * @param string $why the configuration history's description
     * @return array ['ok' => bool, 'message' => ?string, 'created' => bool]
     */
    public static function change(array $add, array $remove, string $why, ?array $reasons = null): array
    {
        $created = false;
        $backend = new Backend();

        /* the category first: alias and rule name it, and core validates a
           relation against what is saved (§4.85) */
        [$category, $newCategory] = $add !== [] ? self::category() : [self::findCategory(new Category()), false];

        Config::getInstance()->lock();
        $aliases = new Alias();
        $node = self::alias($aliases);
        if ($node === null && $add === []) {
            Config::getInstance()->unlock();
            return ['ok' => true, 'message' => null, 'created' => false];
        }

        $before = $node === null ? '' : (string)$node->content;
        $after = Pause::contentWithout(Pause::contentWith($before, $add), $remove);
        $tagged = false;
        $described = false;
        if ($node === null) {
            $node = $aliases->aliases->alias->Add();
            $node->setNodes(Pause::aliasFields($after, (string)$category));
            if ($reasons !== null) {
                $node->setNodes(['description' => Pause::aliasDescription($reasons)]);
            }
            $created = true;
        } else {
            /* why its devices are paused, in the operator's words (operator, 2026-10-09) */
            if ($reasons !== null && (string)$node->description !== Pause::aliasDescription($reasons)) {
                $node->setNodes(['description' => Pause::aliasDescription($reasons)]);
                $described = true;
            }
            if ($after !== $before) {
                $node->setNodes(['content' => $after]);
            }
            /* made before §4.85: tagged once, when Lens creates the category, never again */
            if ($newCategory && $category !== null) {
                $node->setNodes(['categories' => Pause::withCategory((string)$node->categories, $category)]);
                $tagged = true;
            }
        }

        if ($created || $tagged || $described || $after !== $before) {
            $refused = self::refused($aliases, $node);
            if ($refused !== null) {
                Config::getInstance()->unlock();
                return ['ok' => false, 'message' => $refused, 'created' => false];
            }
            $aliases->serializeToConfig(false, true);
            Config::getInstance()->save(['description' => $why]);
        } else {
            Config::getInstance()->unlock();
        }

        if ($add !== []) {
            Config::getInstance()->lock();
            $filter = new Filter();
            $rules = self::rules($filter);
            $existing = Pause::findRule($rules);
            if ($existing !== null && $newCategory && $category !== null) {
                $rule = $filter->rules->rule->$existing;
                $rule->setNodes(['categories' => Pause::withCategory((string)$rule->categories, $category)]);
                $filter->serializeToConfig(false, true);
                Config::getInstance()->save([
                    'description' => gettext('Lens: the rule for paused devices, in category Lens'),
                ]);
            } elseif ($existing === null) {
                $rule = $filter->rules->rule->Add();
                $rule->setNodes(Pause::ruleFields(Pause::firstSequence($rules), (string)$category));
                $refused = self::refused($filter, $rule);
                if ($refused !== null) {
                    Config::getInstance()->unlock();
                    return ['ok' => false, 'message' => $refused, 'created' => $created];
                }
                $filter->serializeToConfig(false, true);
                Config::getInstance()->save(['description' => gettext('Lens: the rule for paused devices')]);
                $created = true;
            } else {
                Config::getInstance()->unlock();
            }
        }

        /* core's own order (AliasController::reconfigureAction): a new table
           is declared by the filter, then the alias file is written and the
           table filled -- at once, rather than at the next minute's refresh */
        if ($created) {
            $backend->configdRun('filter reload skip_alias');
        }
        $backend->configdRun('template reload OPNsense/Filter');
        $backend->configdRun('filter refresh_aliases');

        return ['ok' => true, 'message' => null, 'created' => $created];
    }

    /**
     * Uninstall: the rule first, then the alias, so nothing ever names an
     * alias that is gone; then the filter, which drops the table.
     *
     * @return bool whether anything was there
     */
    public static function remove(): bool
    {
        $found = false;

        Config::getInstance()->lock();
        $filter = new Filter();
        $uuid = Pause::findRule(self::rules($filter));
        if ($uuid !== null) {
            $filter->rules->rule->del($uuid);
            $filter->serializeToConfig(false, true);
            Config::getInstance()->save(['description' => gettext('Lens removed: the rule for paused devices')]);
            $found = true;
        } else {
            Config::getInstance()->unlock();
        }

        Config::getInstance()->lock();
        $aliases = new Alias();
        $node = self::alias($aliases);
        if ($node !== null) {
            $aliases->aliases->alias->del($node->getAttribute('uuid'));
            $aliases->serializeToConfig(false, true);
            Config::getInstance()->save(['description' => gettext('Lens removed: the alias lens_paused')]);
            $found = true;
        } else {
            Config::getInstance()->unlock();
        }

        /* the category last, and only when nothing else refers to it: an
           operator's own category of the same name is theirs (§4.85) */
        Config::getInstance()->lock();
        $categories = new Category();
        $category = self::findCategory($categories);
        $config = Config::getInstance()->object();
        if ($category !== null && !$config->xpath("//*[contains(text(), '{$category}')]")) {
            $categories->categories->category->del($category);
            $categories->serializeToConfig(false, true);
            Config::getInstance()->save(['description' => gettext('Lens removed: the category Lens')]);
        } else {
            Config::getInstance()->unlock();
        }

        if ($found) {
            $backend = new Backend();
            $backend->configdRun('filter reload');
            $backend->configdRun('template reload OPNsense/Filter');
        }

        return $found;
    }

    /**
     * The addresses core resolved the alias to, as pf holds them now.
     */
    public static function addresses(): array
    {
        $raw = json_decode((string)(new Backend())->configdpRun('filter list table', [Pause::ALIAS]), true);

        return array_values(array_filter(array_map(function ($item) {
            return is_array($item) ? (string)($item['ip'] ?? '') : '';
        }, (array)($raw['items'] ?? [])), 'strlen'));
    }

    /**
     * A block rule does not end what is already open: drop the states of the
     * addresses a pause added, one call each (core ANDs several, plan §2).
     *
     * @return int how many addresses were cleared
     */
    public static function dropStates(array $addresses): int
    {
        $backend = new Backend();
        foreach ($addresses as $address) {
            $backend->configdpRun('filter kill states', [$address, '']);
        }

        return count($addresses);
    }

    /**
     * The category "Lens", created when there is none.
     *
     * @return array [uuid or null, whether it was created just now]
     */
    private static function category(): array
    {
        Config::getInstance()->lock();
        $model = new Category();
        $uuid = self::findCategory($model);
        if ($uuid !== null) {
            Config::getInstance()->unlock();
            return [$uuid, false];
        }
        $node = $model->categories->category->Add();
        $node->setNodes(Pause::categoryFields());
        if (self::refused($model, $node) !== null) {
            /* the pause matters more than its label: go on without one */
            Config::getInstance()->unlock();
            return [null, false];
        }
        $model->serializeToConfig(false, true);
        Config::getInstance()->save(['description' => gettext('Lens: the category Lens')]);

        return [$node->getAttribute('uuid'), true];
    }

    private static function findCategory(Category $model): ?string
    {
        foreach ($model->categories->category->iterateItems() as $uuid => $node) {
            if ((string)$node->name === Pause::CATEGORY) {
                return (string)$uuid;
            }
        }

        return null;
    }

    private static function alias(Alias $model)
    {
        foreach ($model->aliases->alias->iterateItems() as $node) {
            if ((string)$node->name === Pause::ALIAS) {
                return $node;
            }
        }

        return null;
    }

    private static function rules(Filter $model): array
    {
        $out = [];
        foreach ($model->rules->rule->iterateItems() as $uuid => $node) {
            $out[$uuid] = [
                'action' => (string)$node->action,
                'source_net' => (string)$node->source_net,
                'interface' => (string)$node->interface,
                'interfacenot' => (string)$node->interfacenot,
                'sequence' => (string)$node->sequence,
            ];
        }

        return $out;
    }

    /**
     * Core's validation, kept to the one node Lens touched: a mistake in some
     * other rule is the operator's, and must not stop a pause or be "fixed".
     */
    private static function refused($model, $node): ?string
    {
        $messages = [];
        foreach ($model->performValidation() as $message) {
            if (strpos($message->getField(), $node->__reference) !== false) {
                $messages[] = $message->getMessage();
            }
        }

        return $messages === [] ? null : implode(' ', $messages);
    }
}
