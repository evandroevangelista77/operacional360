<?php

namespace GlpiPlugin\Operacional360;

/**
 * Seção "Operacional" do menu lateral: o painel (todo perfil com o direito de leitura).
 */
class Menu
{
    /** Direito do plugin: ver o painel (o escopo por cliente e host group é aplicado pela API). */
    public static string $rightname = 'plugin_operacional360';

    /** Chave da seção no menu lateral. */
    public const SECTOR = 'operacional360';

    public const PAGE = '/plugins/operacional360/front/painel.php';

    public static function getMenuContent(): array|false
    {
        if (!Settings::canView()) {
            return false;
        }
        $content = [
            'title'            => 'Operacional',
            'is_multi_entries' => true,
            'operacional360'   => [
                'title' => 'Operacional 360',
                'page'  => self::PAGE,
                'icon'  => self::getIcon(),
            ],
        ];
        return $content;
    }

    public static function getIcon(): string
    {
        return 'ti ti-chart-dots-3';
    }
}
