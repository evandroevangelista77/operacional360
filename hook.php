<?php

use GlpiPlugin\Operacional360\Menu;

/**
 * Instalação: cria o direito de ver o painel (todo perfil da interface central).
 * Não cria tabelas nem guarda configuração: os acessos vêm do NOC 360.
 */
function plugin_operacional360_install(): bool
{
    global $DB;

    // criar o direito só se ainda não existir (repetir a instalação daria chave duplicada)
    if (!countElementsInTable(ProfileRight::getTable(), ['name' => Menu::$rightname])) {
        ProfileRight::addProfileRights([Menu::$rightname]);
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable(), 'WHERE' => ['interface' => 'central']]) as $row) {
        $pid = (int) $row['id'];
        $current = (int) ($DB->request(['SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(),
            'WHERE' => ['profiles_id' => $pid, 'name' => Menu::$rightname]])->current()['rights'] ?? 0);
        if (!$current) {
            // a entidade e o host group já limitam o que cada um vê: todo perfil pode abrir o painel
            $DB->update(ProfileRight::getTable(), ['rights' => READ], ['profiles_id' => $pid, 'name' => Menu::$rightname]);
        }
    }

    unset($_SESSION['glpimenu']);

    return true;
}

function plugin_operacional360_uninstall(): bool
{
    ProfileRight::deleteProfileRights([Menu::$rightname]);
    // configuração antiga (versões até 1.x guardavam tokens e mapa no banco): remove
    Config::deleteConfigurationValues('plugin:operacional360');
    unset($_SESSION['glpimenu']);

    return true;
}
