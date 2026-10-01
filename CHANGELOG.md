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

## [1.2.4] — 2026-10-01

Desempenho, segurança e três correções de correção — uma podia fazer produtos perderem a imagem, outra mantinha a limpeza de placeholders permanentemente bloqueada. Nenhuma alteração ao que é importado, ao que é mostrado, nem às taxonomias.

### Corrigido — custo no frontend

- **`rwbe_import_progress` voltava a ficar `autoload = yes`.** O ciclo de importação apagava e recriava a opção com `update_option()` sem o argumento de autoload, pelo que um blob de vários KB (resultados + mensagens de erro) era carregado em **todos** os pedidos do site, incluindo a loja — e reescrito a cada 10 produtos durante uma importação, invalidando a cache `alloptions` de cada vez. Todas as 11 chamadas passam `false`, e uma nova migração (`rwbe_autoload_fixed_v2`, também ligada ao cron da importação) repara as linhas já existentes.
- **O logger tocava no disco em cada pedido.** `RWBE_Debug_Logger::init()` corria `wp_upload_dir()` e dois `file_exists()` em cada carregamento de página, mesmo com o log desligado. Passou a resolver o caminho só na primeira escrita real.
- **O mapa de veículos era consultado em páginas que não o usam.** O shortcode e o widget montavam os dados localizados — um nonce e `RWBE_Vehicle_Map::get_urls()`, ou seja uma leitura de opção não-autoloaded e dois `file_exists()` — em cada pedido do frontend. Agora só quando os assets são efetivamente carregados, e `get_urls()` ficou memoizado por pedido.
- **`RWBE_Fitment::is_ready()` corria `SHOW TABLES` a cada chamada.** Memoizado por pedido (invalidado por `install()` e `flush_coverage_cache()`).

### Corrigido — custo da importação

- **Um pedido HTTP por produto no cron.** `update_product_stock_price()` chamava `/stock` produto a produto, em série, com timeout de 45 s: ~35 000 idas e voltas numa sincronização completa. O stock de cada lote passa a ser obtido em paralelo (`prefetch_stock()`, pelo mesmo cliente `Requests` já usado nos detalhes); produtos cujo pedido falhe ficam fora da cache e são repetidos individualmente, exatamente como antes.
- **O catálogo inteiro era regravado em cada corrida.** O cron fazia `$product->save()` + `wc_delete_product_transients()` + `clean_post_cache()` para todos os produtos, mesmo sem nada ter mudado. Agora só quando algo muda de facto — incluindo a correção de uma edição manual feita no wp-admin, que conta como alteração. A deteção usa `update_meta_if_changed()`, porque `update_post_meta()` compara estritamente contra a string devolvida pela base de dados e `"5" === 5` é falso: com ints e floats (stock e preços) **todos** os produtos pareciam alterados.
- **Escrita no log por linha.** Cada `RWBE_Debug_Logger::log()` fazia `clearstatcache()` + `filesize()` + um `file_put_contents()` com `FILE_APPEND`. As entradas passam a ser acumuladas e escritas em bloco (64 KB ou 200 entradas), com descarga garantida no fim do pedido mesmo em erro fatal. O que é registado não mudou.
- **Uma leitura e uma escrita de opção por produto no log em tempo real.** `add_product_log()` lia e regravava uma opção de até 100 entradas a cada produto. Passa a ler uma vez por pedido e a gravar no máximo a cada 2 s — o painel atualiza a cada 3 s, pelo que continua igualmente atual.
- **Contagem de termos a cada atribuição.** As duas rotinas de importação correm agora dentro de `wp_defer_term_counting()`, com as contagens recalculadas uma vez no fim.
- **`_nocache` único por chamada.** Cada um dos ~70 000 pedidos de uma importação tinha um URL irrepetível, tornando todas as respostas incacheáveis ao longo do caminho. O token passou a ser único por pedido PHP (continua a garantir dados frescos em cada corrida) e a intenção é transmitida como deve ser, pelo cabeçalho `Cache-Control: no-cache`.
- **Watchdog ruidoso.** `rwbe_check_interrupted_imports` corre a cada 2 minutos e registava entradas à entrada e à saída mesmo sem nada para fazer (~2800 por dia), o que obrigava o log a rodar e empurrava para fora o diagnóstico útil. Só decisões reais são registadas.

### Corrigido — limpeza de imagens placeholder duplicadas

Medido no catálogo real: 195 179 anexos, dos quais **73 218 são cópias do placeholder** à espera de eliminação.

- **Produtos podiam perder a imagem.** `reassign_attachment_references()` não devolvia nada e ignorava o resultado de `$wpdb`: se o `SELECT`/`UPDATE` do `_thumbnail_id` ou o `REGEXP` das galerias falhasse (p. ex. `regexp_time_limit`, que está no valor por omissão de 32), a função devolvia "nada encontrado" e o lote **eliminava os anexos de qualquer forma**. Sem o reaponto, as duas consequências são diferentes: o `wp_delete_attachment()` do WordPress apaga as linhas `_thumbnail_id` que apontem para o anexo, pelo que o produto fica **sem imagem destacada**; já o `_product_image_gallery` é meta do WooCommerce que o core não toca, pelo que **o ID morto fica na lista** e a galeria renderiza um buraco. Agora devolve `bool`, o lote aborta sem eliminar nada e o cursor não avança — ficar com duplicados é recuperável, perder imagens não é.
- **Um erro fatal dentro de `wp_delete_attachment()` bloqueava a limpeza para sempre.** O `catch` só apanhava `Exception`, mas um hook `delete_post` de terceiros lança `\Error`. Escapava: o lock ficava retido, o progresso não era gravado e o watchdog voltava a agendar exatamente o mesmo lote em ciclo. Passou a `\Throwable` com `finally` para libertar o lock e gravar o estado.
- **A varredura passava por todos os anexos em vez de só pelos candidatos.** O WordPress guarda o tamanho do ficheiro em `_wp_attachment_metadata`, pelo que o tamanho conhecido do placeholder (11 137 bytes) pré-filtra em SQL: **185 281 anexos a varrer passam a 73 218** (618 lotes para 245). Os outros 112 mil eram abertos e hasheados do disco sem necessidade. O `md5_file()` continua a ser quem decide — o pré-filtro só pode deixar um duplicado para trás (apanhado numa passagem seguinte), nunca eliminar a imagem errada; anexos sem metadados, ou sem o tamanho registado, permanecem candidatos.
- **Sem caches primadas.** A janela chega como IDs em SQL cru, pelo que `get_attached_file()` e `wp_delete_attachment()` disparavam cada um a sua consulta: ~185 mil consultas por passagem. Um `_prime_post_caches()` por lote resolve em duas.
- **A contagem do que falta corria em cada lote.** Medida em ~1,7 s (varre o conjunto de candidatos todo) e serve apenas para a barra de progresso. Passa a ser feita uma vez por passagem; o resto é derivado de `total - scanned`.

Em conjunto, o custo em SQL de uma passagem completa desce de ~14,6 min para ~2,8 min. As 73 mil chamadas a `wp_delete_attachment()` mantêm-se — é trabalho real.

### Segurança

- **Endpoints AJAX sem verificação de permissões.** `rwbe_get_import_logs`, `rwbe_start_import`, `rwbe_stop_import` e `rwbe_resume_import` validavam o nonce e mais nada. Um nonce prova de que página veio o pedido, nunca que quem o faz tem autorização — e estes arrancam e param uma importação de catálogo completo. Estão registados sem variante `nopriv` e o nonce só chega a uma página que já exige `manage_options`, pelo que não era diretamente explorável, mas é a verificação de capacidade que impõe isso. Passam por `authorize_ajax()`, que verifica `manage_options` primeiro (um chamador não autorizado não fica a saber se o nonce era válido) e só depois o nonce, agora também sanitizado.
- **Scripts de diagnóstico acessíveis por HTTP.** Os nove ficheiros em `tools/` não tinham guarda nenhuma e vivem num diretório servido pelo servidor web — endpoints não autenticados que falam com a API do fornecedor. Passam a recusar tudo o que não seja `PHP_SAPI === 'cli'`.
- **Sete ficheiros em `includes/` sem guarda de acesso direto.** Acrescentada no mesmo estilo dos outros três. Por HTTP todos devolvem corpo vazio sem executar nada.
- **`uninstall.php` não existia.** Apagar o plugin deixava para trás a tabela `wp_rwbe_fitment`, catorze opções (incluindo o API token), os transients, cinco eventos agendados e dois diretórios em `uploads/`. As opções são apanhadas pelo prefixo do próprio plugin, para que uma opção acrescentada mais tarde não fique esquecida, e cada site de uma rede é tratado em separado. Não toca em produtos, anexos nem nas taxonomias `pa_*`: isso é conteúdo do site. O apagamento recursivo é contido — o caminho tem de resolver dentro da base de `uploads`, estar diretamente abaixo dela e ter o nome esperado — e tem teste que verifica que media real sobrevive, que um nome com travessia é recusado e que um symlink para fora da árvore não é seguido.

### Corrigido — importação presa sem token

- **O watchdog ressuscitava indefinidamente uma importação que não podia funcionar.** `import_products()` verifica o API token antes de tudo; `import_products_with_resilience()` — o caminho que o cron e o watchdog usam — não verificava. Como a progressão é escrita como `in_progress` com timestamp fresco *antes* de a API ser chamada, num site sem token o watchdog retomava a mesma importação condenada de dois em dois minutos, cada tentativa renovando o timestamp. Efeito colateral: a limpeza de placeholders, que cede sempre que uma importação parece ativa, nunca conseguia arrancar. Ambos os caminhos verificam agora, e limpam a progressão presa em vez de a repetirem para sempre.

### Alterado

- **Filtro da loja por JOIN em vez de `post__in`.** `rwbe_make` + `rwbe_model` (+ `rwbe_year`) ligam a tabela de fitment à query por `INNER JOIN` + `DISTINCT` (via `posts_clauses`, só na query marcada), em vez de resolver até 20 000 ids e passá-los em `post__in`. Como consequência **o filtro `rwbe_fitment_filter_max_ids` deixou de existir**: já não há truncatura, pelo que o caminho da combinação exata nunca volta a cair na taxonomia — antes alargava silenciosamente os resultados precisamente nas marcas com mais produtos.
- **`RWBE_Fitment::get_model_map()` e `get_year_map()` aceitam filtros.** Os menus pediam o mapa completo (varrimento da tabela inteira mais expansão de todos os intervalos de anos em PHP) para depois usar uma única chave. O construtor do mapa JSON estático continua a chamá-los sem argumentos.
- **Estatísticas do painel em cache por 2 minutos.** Os quatro `COUNT` sobre `wp_postmeta` corriam sem cache em cada carregamento das páginas do plugin. O progresso em tempo real continua a ser lido na página de importação.
- **`_rwbe_status_updated_at`** passa a ser gravado só quando a sincronização altera algo, em vez de em cada produto em cada corrida. Nada lê este valor; é apenas diagnóstico.
- **Identificação do placeholder passa a ser filtrável.** `rwbe_placeholder_md5` e `rwbe_placeholder_size` permitem adaptar a deteção sem editar o plugin. O hash descreve um ficheiro que pertence ao fornecedor: no dia em que a RWBE reexportar aquele PNG, sem isto cada site precisaria de uma versão nova antes de voltar a desduplicar placeholders.
- **Código conforme as WordPress Coding Standards.** De 11 990 erros e 760 avisos para zero. A indentação passou a tabulações, seguindo o WordPress. Os desvios que ficam estão documentados um a um em `phpcs.xml.dist`, com a razão ao lado — nenhum esconde um problema por resolver. Pelo caminho a norma revelou defeitos reais, todos corrigidos: um `esc_html__()` sem text domain (a etiqueta do agendamento nunca seria traduzida), 96 `_e()` a imprimir traduções sem escapar, nove sítios no backoffice a emitir valores crus, treze `in_array()` sem comparação estrita, dois `==` que deviam ser `===`, `date()` onde o fuso horário não pode contar, e duas variáveis de escopo de ficheiro a vazar para o namespace global.

### Desenvolvimento

- **Ferramentas e CI, que não existiam.** `composer.json` com dependências só de desenvolvimento e os scripts `lint`, `cs`, `cs:fix`, `test` e `check`; `phpcs.xml.dist`, `phpunit.xml.dist`, `.editorconfig` e `bin/lint.php`. O GitHub Actions valida a sintaxe em PHP 7.4 a 8.4 — o mínimo que o cabeçalho do plugin promete — e corre as normas e os testes em 8.3.
- **Oito suites de teste** em `tests/suites/`, uma por processo, sem precisar de uma instalação do WordPress: deteção de alterações, buffer do logger, limitação do log em tempo real, SQL de fitment e injeção de cláusulas na query da loja, SQL da limpeza, caminho de aborto da limpeza, rotina de desinstalação e a guarda do token.

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
