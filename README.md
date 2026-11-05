# Operacional 360 (plugin GLPI 11 e 12)

Painel operacional por cliente: chamados do GLPI da OAK e alertas do Zabbix, no mesmo layout do NOC 360.

- **Sem tela de configuração.** Usa os mesmos acessos do NOC 360 (`/etc/datacenter360/config.php` e, se existir, `/var/lib/datacenter360/config.json`): Zabbix, GLPI da OAK (API REST), entidade raiz (`glpi_entity_prefix`), severidades, SLA e a tabela `client_groups`. O servidor do GLPI precisa conseguir ler esse arquivo (grupo `www-data`).
- **Clientes** (chamados do GLPI): cada entidade filha direta da raiz. O seletor **Cliente(s)** no painel aceita vários.
- **Host groups** (alertas do Zabbix): o seletor **Host group(s)** lista os host groups do Zabbix. Em *Automático*, valem os host groups ligados aos clientes (por `client_groups` ou pelo nome); escolhendo, vale só o que foi marcado.
- As escolhas ficam na URL (`?client=A|B&group=12|34`) e no navegador de cada usuário.
- Menu: apenas **Operacional → Operacional 360**.

Versão 2.0.0 · GPLv3+
