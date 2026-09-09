# Changelog

Todas as alterações relevantes do **RWBE Product Importer** são registadas neste ficheiro.

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/);
o projeto segue [Versionamento Semântico](https://semver.org/lang/pt-BR/).

---

## O que o plugin faz

- **Importa o catálogo da API RWBE para o WooCommerce** — cria e atualiza produtos com SKU (`itemCode`), título, descrições, preço sem IVA, promoções (`grossPromoPricing` + `promoSpecs`), stock, disponibilidade (`status`), categoria (`segment`), marca (`brand`, com logótipo), grupo (`group`), atributos técnicos, imagem destacada e galeria.
- **Dois modos de importação** — completa manual (todos os campos) e cron 2×/dia incremental (stock e preço, via `dateUpdated`, com reconciliação completa a cada 24 h).
- **Importação resiliente** — lock de concorrência, guarda de progresso, retoma automática após timeout ou falha de rede, e botões Iniciar/Parar/Retomar.
- **Taxonomias e atributos automáticos** — `pa_brands`, `pa_grupo`, `pa_make`, `pa_model`, `pa_vehicle_year` e `product_brand`.
- **Combinações reais de veículo** — tabela `wp_rwbe_fitment` (marca + modelo + intervalo de anos por linha), que impede cruzamentos impossíveis do tipo "Beta + EC 250".
- **Barra de pesquisa Marca → Modelo → Ano** — shortcode `[rwbe_ymm_search]` e widget de filtro, servidos por mapas JSON estáticos pré-calculados.
- **Backoffice** — configurações, estado da importação, estatísticas, taxonomias, cron, teste de ligação à API, debug log rotativo, painel de importação em tempo real e limpeza de imagens placeholder duplicadas.
- **Compatível com HPOS** do WooCommerce.

Detalhe de utilização e configuração: [README.md](README.md).

---

## [1.2.3] — 2026-09-09

### Segurança
- **Token da API removido do código.** O `define('RWBE_API_AUTH_TOKEN', '…')` com o token embutido no ficheiro principal foi eliminado. O token passa a ser sempre introduzido manualmente pelo administrador, no campo **API Token** das Configurações, ou definido na constante `RWBE_API_AUTH_TOKEN` do `wp-config.php` (que tem prioridade sobre o campo).
- **Scripts de teste sem credenciais.** Os oito scripts de diagnóstico (`api-*.php`, `brand-test.php`, `browser-api-test.php`, `standalone-api-test.php`) deixaram de conter o token; leem-no da variável de ambiente `RWBE_API_TOKEN` ou, quando o WordPress está carregado, de `rwbe_get_api_token()`, e terminam com aviso se não houver token.
- **Campo do token deixou de expor a constante.** O campo das Configurações passou a `type="password"` e mostra apenas o valor guardado na opção — antes pré-preenchia o valor da constante do `wp-config.php`, que acabava copiado para a base de dados ao guardar. Quando a constante está definida, o ecrã indica-o.

> ⚠️ O token que estava em código deve ser considerado comprometido e rodado junto da RWBE.

### Adicionado
- `rwbe_has_api_token()` para verificar se existe credencial configurada.
- Aviso no backoffice, para administradores, enquanto não houver token configurado.
- **Traduções ativadas** — `load_plugin_textdomain()` passou a ser chamado no `init` e a pasta `languages/` inclui o modelo `rwbe-product-importer.pot` com as 223 strings do plugin. O cabeçalho declarava `Domain Path: /languages` sem que a pasta existisse nem o domínio fosse carregado, pelo que nenhuma tradução podia ser aplicada.
- `LICENSE` (GPL v2) e `.gitignore`; cabeçalho do plugin com `License` e `License URI`.

### Alterado
- **Autoria no cabeçalho** — `Author: António Lopes`, `Author URI: https://www.antoniolopes.io`; `Plugin URI` removido e os antigos valores `example.com` deixaram de existir.
- `Requires PHP` passou de 7.2 para **7.4** (testado até 8.3) e `WC tested up to` de 7.0 para **11.0**.
- Os scripts de diagnóstico saíram da raiz do plugin para **`tools/`**, com um `README.md` próprio e caminhos de bootstrap absolutos (`dirname(__DIR__, 4)`), em vez de relativos ao diretório de trabalho. A pasta pode ser apagada em produção.
- `rwbe_get_api_token()` passou a preferir a constante `RWBE_API_AUTH_TOKEN` e a devolver string vazia quando nada está configurado (em vez de cair num token por defeito).
- Guardas de "sem token" em todos os pontos de entrada da API — `api_get()` devolve `WP_Error`, `import_products()` aborta com mensagem, `fetch_products_from_api()` e `backfill_fitment_batch()` não arrancam, e o testador de ligação explica o que falta. Antes, sem token, eram enviados pedidos com um cabeçalho `Bearer` vazio.

---

## [1.2.2] — 2026-09-08

Inclui as correções da 1.2.1.

### Alterado
- **A limpeza de placeholders passou a pertencer ao servidor.** O estado do processo vive numa opção, o trabalho corre em lotes agendados por cron, e a página do admin apenas acompanha. Fechar o separador ou mudar de janela deixou de interromper a limpeza — ao voltar, o progresso reaparece.
- Botões **Continuar Limpeza** e **Parar Limpeza**, com retoma a partir do cursor guardado.

### Corrigido
- O pedido de paragem e a deteção de "importação a decorrer" passaram a ser lidos com uma consulta direta a `wp_options`: dentro do mesmo pedido, `get_option()` devolvia o valor em cache e a paragem só era vista no pedido seguinte. Um progresso com mais de 5 minutos é tratado como obsoleto.
- A imagem placeholder canónica é recuperada quando a opção que a guarda se perde, em vez de o processo apagar ficheiros ainda em uso.
- A cobertura de fitment passou a ter em conta o estado do backfill em curso.

---

## [1.2.0] — 2026-09-04

### Adicionado
- **Tabela `wp_rwbe_fitment` com as combinações reais de veículo** (`class-rwbe-fitment.php`). Cada aplicação da API é guardada intacta — marca, modelo e intervalo de anos na mesma linha — em vez de ser dispersa por três atributos independentes. Uma linha por aplicação (intervalo, não um ano por linha), pelo que a tabela fica do tamanho do payload da API.
  - Efeito medido no produto 36352 (24 aplicações, 6 marcas): os modelos listados para "Beta" passaram de 84 (incluindo modelos GasGas) para 3, todos Beta; a pesquisa "Beta + EC 250" passou de 2 produtos para 0 — a moto não existe.
  - Intervalos sentinela da API (`1950-9999`) são guardados como `0-0`.
- **Filtro da loja por combinação exata** — `rwbe_make` + `rwbe_model` (+ `rwbe_year`) passam a filtrar via `post__in` a partir da tabela. Só entra quando há modelo ou ano; acima de `rwbe_fitment_filter_max_ids` (20000) volta ao caminho por taxonomia, para nunca esconder produtos por truncatura.
- **Interruptor de cobertura** — `RWBE_Fitment::is_ready()` exige 90% dos produtos com marca já verificados (filtro `rwbe_fitment_min_coverage`). Abaixo disso, menus, mapa estático e filtro mantêm exatamente o comportamento anterior.
- **Preenchimento a partir da API** em **Barra de Pesquisa → Preencher a partir da API**: lotes de 200 por minuto em segundo plano (`rwbe_fitment_backfill`), sem tocar nos produtos. Cerca de 3 horas para 36 mil produtos.
- Meta `_rwbe_fitment_checked` para não voltar a pedir produtos sem aplicações (peças universais).
- Limpeza das linhas de fitment quando um produto é eliminado (`before_delete_post`); instalação e migração do esquema na ativação e no início de cada cron.

### Alterado
- Os menus Marca → Modelo → Ano, o widget de filtro e a geração do mapa estático passam a usar as combinações reais quando a tabela está pronta.

---

## [1.1.0] — 2026-09-04

### Adicionado
- **Mapa estático de veículos em JSON** (`class-rwbe-vehicle-map.php`), servido diretamente pelo servidor web:
  `uploads/rwbe-vehicle-map/makes-models.json` e `model-years.json`.
  No conjunto atual (~35 mil produtos, 67 marcas, 1789 modelos) cada ficheiro pesa ~24 KB comprimido e responde em ~5 ms, contra ~310 ms de uma chamada a `admin-ajax.php` — que arranca WordPress, WooCommerce e tema só para devolver uma lista.
- Geração do mapa no fim de cada importação (`rwbe_product_import_cron`, prioridade 100, logo após a limpeza de caches), em segundo plano quando os ficheiros faltam (`rwbe_vehicle_map_rebuild`), e manualmente em **Barra de Pesquisa → Reconstruir mapa agora**.
- Recurso automático aos endpoints AJAX antigos quando os ficheiros não existem (instalação nova, `uploads` sem permissões, antes da primeira importação). O filtro funciona sempre; sem mapa é apenas mais lento.
- Secção **Desempenho do filtro** no README, com recomendações de servidor (cron de sistema, `pm.max_children`, object cache).

### Alterado
- As caches dos menus passaram a ser invalidadas por *namespace* de versão (`rwbe_ymm_cache_version`, `rwbe_vf_cache_version`) em vez de `wp_cache_flush()`, que deitava fora a cache de objetos de todo o site por causa de meia dúzia de transients.
- O JavaScript do shortcode e do widget partilham o mesmo mapa e pedem cada ficheiro no máximo uma vez por página.

---

## [1.0.1] — 2026-09-01

### Adicionado
- **Limpeza de imagens placeholder duplicadas.** Quando um produto não tem foto, a API devolve sempre a mesma imagem "Race Winning Brands"; importações antigas criaram uma cópia física por produto (dezenas de milhares de ficheiros). A ferramenta mantém uma cópia partilhada, reaponta todos os produtos para ela e apaga as restantes (ficheiros e registos), em lotes, com contadores de progresso.
- **Reutilização do placeholder na importação** — identificado por MD5 e tamanho, deixa de ser duplicado em importações futuras.
- **Lock de importação** (`rwbe_product_import`): só uma importação corre de cada vez. Sem isto, o vigia de importações interrompidas, um segundo clique ou o cron podiam arrancar uma corrida paralela sobre o mesmo offset e criar produtos duplicados com o mesmo SKU.
- **Pedido de paragem** (`rwbe_import_stop_requested`) respeitado pela importação e pela limpeza.
- Importação com retoma automática após falha (`run_import_with_resilience`).

### Alterado
- A limpeza é bloqueada enquanto uma importação estiver a decorrer.

---

## [1.0.0] — 2026-08-05

Primeira versão em uso.

### Adicionado
- Importação de produtos da API RWBE para o WooCommerce, com mapeamento completo dos campos e criação automática de taxonomias e atributos (`pa_brands`, `pa_grupo`, `pa_make`, `pa_model`, `pa_vehicle_year`, `product_brand`).
- Importação completa manual e cron incremental 2×/dia, mais vigia de importações interrompidas (`rwbe_check_interrupted_imports`).
- Preços tratados como valores sem IVA, com o produto marcado como *taxable* para o WooCommerce calcular o imposto no checkout.
- Galeria e imagem destacada, logótipos de marca e atributos técnicos.
- Backoffice **RWBE Importer**: configurações (token da API e debug log), estado da importação, estatísticas, taxonomias, cron, teste de ligação à API, produtos recentemente processados e informações do sistema.
- **Importação em Tempo Real** — painel ao vivo (3 s) com ritmo, progresso, contadores e detalhe por produto.
- Shortcode `[rwbe_ymm_search]` (Marca → Modelo → Ano) e widget de filtro de veículo.
- Debug log rotativo (~5 MB) em `uploads/rwbe-logs/`.
- Migração única das opções do plugin para `autoload = no`, para deixarem de ser carregadas em todos os pedidos da loja.
- Compatibilidade com HPOS e finalização dos produtos pela API do WooCommerce, para manter as tabelas de pesquisa sincronizadas.
