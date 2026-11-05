<?php

namespace GlpiPlugin\Operacional360;

/** Cache em arquivo temporário (por chave), para não repetir consultas ao Zabbix e ao GLPI a cada atualização do painel. */
class Cache
{
    /** Guarda o resultado de $fn por $ttl segundos (0 = sem cache). */
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $file = sys_get_temp_dir() . '/op360_' . md5($key);
        if ($ttl > 0 && is_file($file) && time() - (int) filemtime($file) < $ttl) {
            $value = json_decode((string) file_get_contents($file), true);
            if ($value !== null) {
                return $value;
            }
        }
        $value = $fn();
        if ($ttl > 0) {
            @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            @chmod($file, 0600);
        }
        return $value;
    }
}
