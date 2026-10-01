<div align="center">

# RWBE Product Importer

**Sincroniza o catálogo da Race Winning Brands Europe com o WooCommerce — e dá à loja uma pesquisa de peças por veículo que devolve resultados que existem mesmo.**

[![Versão](https://img.shields.io/badge/versão-1.2.4-0b7285.svg)](CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-3.0%20→%2011.0-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%20→%208.3-777bb4.svg)](https://www.php.net/)
[![HPOS](https://img.shields.io/badge/HPOS-compatível-2f9e44.svg)](https://developer.woocommerce.com/2022/09/14/high-performance-order-storage-progress-report/)
[![Licença](https://img.shields.io/badge/licença-GPL--2.0-blue.svg)](LICENSE)

</div>

---

## Índice

- [Porquê](#porquê)
- [Funcionalidades](#funcionalidades)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Importação de produtos](#importação-de-produtos)
- [Barra de pesquisa por veículo](#barra-de-pesquisa-por-veículo)
- [Combinações de veículo](#combinações-de-veículo)
- [Desempenho do filtro](#desempenho-do-filtro)
- [Mapeamento de campos da API](#mapeamento-de-campos-da-api)
- [Taxonomias e atributos](#taxonomias-e-atributos)
- [Automação (cron)](#automação-cron)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Resolução de problemas](#resolução-de-problemas)
- [Notas técnicas](#notas-técnicas)
- [Histórico de versões](#histórico-de-versões)
- [Licença](#licença)

---

## Porquê

Um catálogo de peças de competição tem duas exigências que um importador genérico não resolve:

1. **Escala.** Dezenas de milhares de produtos que mudam de preço e stock todos os dias. Uma importação que rebente a meio tem de saber onde ficou — e nunca duplicar o que já criou.
2. **Aplicações de veículo.** "Serve na minha mota?" é a única pergunta que interessa ao cliente. Guardar marca, modelo e ano como três listas separadas responde mal: cruza uma Beta com um modelo GasGas e manda o cliente para um produto que não lhe serve.

Este plugin trata das duas coisas: importação resiliente com retoma automática, e uma tabela dedicada que preserva cada aplicação intacta — marca, modelo e intervalo de anos na mesma linha.

---

## Funcionalidades

### Importação

- Sincronização completa do catálogo RWBE para o WooCommerce — SKU, títulos, descrições, preços, promoções, stock, disponibilidade, categorias, marcas, grupos, atributos técnicos, imagem destacada e galeria.
- **Dois modos**: importação completa manual e cron incremental 2×/dia (só stock e preço, via `dateUpdated`), com reconciliação completa a cada 24 h.
- **Resiliência**: lock de concorrência que impede importações paralelas e produtos duplicados, progresso guardado, retoma automática após timeout ou falha de rede, e paragem a pedido.
- **Sem imagens duplicadas**: o placeholder do fornecedor é reconhecido por hash e partilhado por todos os produtos sem foto, em vez de ser copiado dezenas de milhares de vezes.

### Pesquisa por veículo

- Shortcode `[rwbe_ymm_search]` — Marca → Modelo → Ano, com listas encadeadas.
- Widget de filtro de veículo para barras laterais e páginas de loja.
- **Combinações reais** guardadas em `wp_rwbe_fitment`: os menus só mostram o que existe, e o filtro da loja devolve exatamente os produtos que servem aquele veículo.
- **Mapas estáticos JSON** pré-calculados: cada nível responde em ~5 ms, sem arrancar o WordPress.

### Backoffice

| Ecrã | O que dá |
|---|---|
| **Configurações** | Token da API e interruptor do debug log |
| **Estado da importação** | Indicador ao vivo, barra de progresso e contadores (criados / atualizados / erros) |
| **Estatísticas** | Produtos RWBE, total WooCommerce, em stock, sem stock, datas das últimas importações |
| **Taxonomias / Atributos** | Estado e nº de termos de cada atributo `pa_*` |
| **Tarefas agendadas** | Estado do cron e próxima execução |
| **Testar conexão API** | Valida a ligação e mostra produtos de amostra |
| **Debug log** | Ver e limpar o registo rotativo |
| **Importação em Tempo Real** | Painel ao vivo (3 s): ritmo em produtos/min, progresso, atividade atual e detalhe por produto |
| **Limpeza de placeholders** | Elimina cópias duplicadas da imagem genérica, em lotes, no servidor. Os candidatos são pré-filtrados pelo tamanho que o WordPress guarda em `_wp_attachment_metadata`; o hash do ficheiro continua a decidir, e nada é eliminado antes de os produtos estarem reapontados para a cópia partilhada |
| **Barra de Pesquisa** | Shortcode, estado dos dados, reconstruir mapa, preencher combinações a partir da API |

---

## Requisitos

- WordPress 5.0 ou superior
- WooCommerce 3.0 ou superior (testado até 11.0)
- PHP 7.4 ou superior (testado até PHP 8.3)
- Token de acesso à API RWBE

---

## Instalação

1. Copie a pasta `rwbe-product-importer` para `wp-content/plugins/`.
2. Ative o plugin no menu **Plugins**.
3. Garanta que o **WooCommerce** está instalado e ativo.
4. Introduza o **API Token** nas Configurações do plugin (ver abaixo).

Na ativação, o plugin cria os atributos necessários, instala a tabela de combinações e agenda os cron jobs.

---

## Configuração

Aceda a **RWBE Importer → (página principal) → Configurações**:

| Definição | Descrição |
|---|---|
| **API Token** | Token de autenticação da API RWBE. **Obrigatório e introduzido manualmente** — o plugin não inclui nenhum token. Em alternativa, defina a constante `RWBE_API_AUTH_TOKEN` no `wp-config.php` (tem prioridade sobre o campo). Sem token, a importação não arranca. |
| **Debug Log** | Liga/desliga o registo de depuração. **Recomendado desligar em produção.** Log rotativo (máx. ~5 MB) em `wp-content/uploads/rwbe-logs/`. |

### Credenciais

O token nunca vive no código. Para o manter fora da base de dados, defina-o no `wp-config.php`:

```php
define('RWBE_API_AUTH_TOKEN', 'o-seu-token');
```

Os scripts de diagnóstico na raiz do plugin leem o token da variável de ambiente `RWBE_API_TOKEN`:

```bash
RWBE_API_TOKEN=o-seu-token php tools/api-test.php
```

### IVA (importante)

Os preços da API são **sem IVA**. O plugin marca cada produto como *taxable* e guarda o preço sem IVA, deixando o WooCommerce calcular o imposto no checkout. Para isto funcionar, configure em **WooCommerce → Definições → Imposto**:

- "Ativar taxas" ligado
- "Preços inseridos com imposto" = **Não**
- Uma taxa configurada (ex.: 23% PT)

---

## Importação de produtos

### Importação completa (manual)

Cria novos produtos e atualiza **todos** os campos (preços, promoções, stock, taxonomias, imagens). Iniciada pelo botão **Iniciar Importação Completa** ou pela página **Importação em Tempo Real**.

### Importação automática (cron)

Corre 2×/dia e atualiza apenas **stock e preço** dos produtos existentes. É **incremental** (só busca produtos alterados desde a última sincronização via `dateUpdated`), com uma **reconciliação completa a cada 24 h** por segurança.

> [!TIP]
> A importação é resiliente: o progresso é guardado e retomado automaticamente em caso de falha de ligação ou timeout.

> [!IMPORTANT]
> Os termos de veículo (marca / modelo / ano) só são preenchidos numa importação **completa**. Execute uma importação completa antes de usar a barra de pesquisa.

---

## Barra de pesquisa por veículo

Coloque o shortcode numa página, bloco ou widget:

```
[rwbe_ymm_search]
```

O cliente escolhe a **marca**, depois o **modelo** e o **ano** — cada nível é carregado com base na escolha anterior — e é levado para a loja filtrada.

### Atributos opcionais

| Atributo | Descrição | Exemplo |
|---|---|---|
| `make_label` | Texto do campo da marca | `make_label="Marca"` |
| `model_label` | Texto do campo do modelo | `model_label="Modelo"` |
| `year_label` | Texto do campo do ano | `year_label="Ano"` |
| `show_year` | Mostrar o 3º nível (Ano). Use `"no"` para esconder | `show_year="no"` |
| `button` | Texto do botão | `button="Procurar peças"` |
| `shop_url` | URL de resultados (por defeito: página da Loja) | `shop_url="/loja/"` |

---

## Combinações de veículo

A API descreve cada aplicação como uma linha completa — marca, modelo e intervalo de anos juntos. Guardar isso em três atributos independentes (`pa_make`, `pa_model`, `pa_vehicle_year`) perde o emparelhamento:

> Uma peça que serve **Beta 250 RR Enduro 2T** e **GasGas EC 250** fica marcada com as duas marcas e os dois modelos. O menu da Beta passa a listar modelos GasGas, e a loja devolve resultados para "Beta + EC 250" — uma moto que não existe.

A tabela `wp_rwbe_fitment` guarda cada aplicação intacta:

| product_id | make_slug | model_slug | year_from | year_to |
|---|---|---|---|---|
| 36352 | beta | 250-rr-enduro-2t | 2013 | 2020 |
| 36352 | gas-gas | ec-250 | 1997 | 2019 |

Uma linha por aplicação (um intervalo, não um ano por linha), pelo que a tabela fica do tamanho do payload da API em vez de rebentar para milhões de linhas.

Intervalos abertos que a API usa como sentinela (`1950-9999`) são guardados como `0-0`: não têm informação de ano utilizável.

**Efeito medido** no produto 36352 (24 aplicações, 6 marcas):

| | Modelos listados para "Beta" | Resultados de "Beta + EC 250" |
|---|---|---|
| Atributos (antes) | 84, incluindo modelos GasGas | 2 produtos |
| Combinações reais | 3, todos Beta | 0 produtos |

### Quando entra em vigor

Nada muda enquanto a tabela não cobrir o catálogo. `RWBE_Fitment::is_ready()` exige 90% dos produtos com marca já verificados (ajustável pelo filtro `rwbe_fitment_min_coverage`); abaixo disso, menus e filtro mantêm exatamente o comportamento anterior — o mesmo interruptor vale para os menus, para o mapa estático e para o filtro da loja.

Para preencher a tabela:

- Uma **importação completa** preenche-a à medida que processa cada produto, sem pedidos extra à API.
- **RWBE Importer → Barra de Pesquisa → Preencher a partir da API** percorre o catálogo em lotes de 200 por minuto, em segundo plano, e escreve apenas as combinações — não toca nos produtos. Cerca de 3 horas para 36 mil produtos.

Produtos sem aplicações (peças universais) ficam marcados com `_rwbe_fitment_checked` para não serem pedidos outra vez.

### Filtro da loja

Com a tabela pronta, `rwbe_make` + `rwbe_model` (+ `rwbe_year`) filtram pela combinação exata, em vez de três cláusulas `tax_query` independentes. A tabela é ligada à query da loja por `INNER JOIN` (com `DISTINCT`, porque um produto tem uma linha por aplicação), através do filtro `posts_clauses` e apenas na query marcada — qualquer `post__in` que outro plugin já tenha definido mantém-se, e o JOIN apenas restringe por cima. Só entra quando há modelo ou ano: uma marca sozinha não tem emparelhamento para errar.

Até à versão 1.2.3 o filtro resolvia primeiro a lista de ids e passava-a em `post__in`, o que colocava um `IN()` de até 20 000 inteiros na query principal e obrigava a um limite (`rwbe_fitment_filter_max_ids`) acima do qual desistia e voltava ao caminho por taxonomia — alargando silenciosamente os resultados precisamente nas marcas com mais produtos. O JOIN não tem esse teto, pelo que o filtro por combinação exata aplica-se sempre e o filtro `rwbe_fitment_filter_max_ids` deixou de existir.

---

## Desempenho do filtro

Os menus Marca → Modelo → Ano não consultam o WordPress a cada escolha. As combinações possíveis são pré-calculadas e guardadas em dois ficheiros JSON servidos diretamente pelo servidor web:

```
wp-content/uploads/rwbe-vehicle-map/makes-models.json   (marca → modelos)
wp-content/uploads/rwbe-vehicle-map/model-years.json    (marca|modelo → anos)
```

No conjunto de dados atual (~35 mil produtos, 67 marcas, 1789 modelos) cada ficheiro pesa cerca de 24 KB comprimido e responde em **~5 ms**, contra os **~310 ms** de uma chamada a `admin-ajax.php` — que arranca o WordPress, o WooCommerce e o tema inteiros só para devolver uma lista.

**Quando é gerado**

- No fim de cada importação (`rwbe_product_import_cron`, prioridade 100), logo a seguir à limpeza das caches — a cache volta quente e nenhum visitante paga o custo frio.
- Em segundo plano (`rwbe_vehicle_map_rebuild`) quando os ficheiros não existem.
- Manualmente em **RWBE Importer → Barra de Pesquisa → Reconstruir mapa agora**.

**Se os ficheiros faltarem** (instalação nova, `uploads` sem permissões, antes da primeira importação) o JavaScript recorre automaticamente aos endpoints AJAX antigos, que continuam registados. O filtro funciona sempre; sem o mapa é apenas mais lento.

### Recomendações de servidor

Não fazem parte do plugin, mas são o que domina o tempo de resposta:

| Item | Porquê |
|---|---|
| `define('DISABLE_WP_CRON', true)` + cron de sistema a cada minuto | Evita que o import ou o Action Scheduler corram dentro do pedido de um visitante |
| `pm.max_children` ≥ 8 no PHP-FPM | Com 2 workers, um cron pesado bloqueia o site inteiro |
| Desligar a regeneração de miniaturas em segundo plano do WooCommerce | Numa loja com >100 mil anexos ocupa workers durante minutos |
| Object cache persistente (Redis/Memcached) | Mantém os transients e `get_terms` fora da base de dados |

---

## Mapeamento de campos da API

| Campo API | Destino WooCommerce |
|---|---|
| `itemCode` | SKU (`_sku`) |
| `title` | Título do produto |
| `description` (fallback `subtitle`) | Descrição longa |
| `subtitle` | Descrição curta |
| `retailerPrice` | Preço regular (sem IVA) |
| `grossPromoPricing` + `promoSpecs` | Preço promocional + datas da promoção |
| `stock` | Quantidade em stock |
| `status` | Disponibilidade — `"A"` publica; outro valor coloca em rascunho |
| `segment` | Categoria do produto |
| `brand` | Atributo `pa_brands` + taxonomia `product_brand` (+ logótipo da marca) |
| `group` | Atributo `pa_grupo` |
| `application` (make / model / beginYear-endYear) | `pa_make`, `pa_model`, `pa_vehicle_year` + tabela `wp_rwbe_fitment` |
| `attributes` (title / value / unit) | Atributos técnicos |
| `photo` / `gallery` | Imagem destacada / galeria |

---

## Taxonomias e atributos

Criados e preenchidos **automaticamente** pelo plugin:

- `pa_brands` — marcas das peças
- `pa_grupo` — grupos de produto
- `pa_make` — marca do veículo
- `pa_model` — modelo do veículo
- `pa_vehicle_year` — ano (anos individuais)
- `product_brand` — taxonomia pública de marca (compatibilidade com temas)

---

## Automação (cron)

| Tarefa | Frequência | Função |
|---|---|---|
| `rwbe_product_import_cron` | 2×/dia | Atualizar stock e preço (incremental) |
| `rwbe_check_interrupted_imports` | 2–5 min | Detetar e retomar importações interrompidas |
| `rwbe_fitment_backfill` | 1×/min enquanto ativo | Preencher combinações de veículo a partir da API |
| `rwbe_vehicle_map_rebuild` | À procura | Regenerar os mapas JSON quando faltam |

> [!NOTE]
> O WP-Cron depende de visitas ao site. Em lojas com pouco tráfego, configure um cron real do servidor a chamar `wp-cron.php`.

---

## Estrutura do projeto

```
rwbe-product-importer/
├── rwbe-product-importer.php                       # Bootstrap: constantes, token, i18n, hooks, ativação
├── uninstall.php                                   # Remove tabela, opções, agendamentos e ficheiros gerados
├── includes/
│   ├── class-rwbe-product-importer.php             # Motor de importação (produtos, imagens, fitment)
│   ├── class-rwbe-product-importer-admin.php       # Backoffice e endpoints AJAX
│   ├── class-rwbe-product-importer-live-log.php    # Painel de importação em tempo real
│   ├── class-rwbe-product-importer-search.php      # Shortcode [rwbe_ymm_search] e filtro da loja
│   ├── class-rwbe-vehicle-filter-widget.php        # Widget de filtro de veículo
│   ├── class-rwbe-vehicle-map.php                  # Mapas estáticos JSON dos menus
│   ├── class-rwbe-fitment.php                      # Tabela wp_rwbe_fitment (combinações reais)
│   ├── class-rwbe-api-tester.php                   # Teste de ligação à API
│   ├── class-rwbe-debug-logger.php                 # Log rotativo em uploads/rwbe-logs/
│   └── class-rwbe-generic-attribute-helper.php     # Criação de atributos pa_* e limpeza de cache
├── assets/
│   ├── css/                                        # admin, live-log, search, vehicle-filter
│   └── js/                                         # admin, live-log, search, vehicle-filter, vehicle-filter-block
├── languages/
│   └── rwbe-product-importer.pot                   # Modelo de tradução
├── tests/
│   ├── SuiteRunnerTest.php                         # Corre cada suite no seu próprio processo
│   ├── helpers.php                                 # Constantes partilhadas pelas suites
│   └── suites/                                     # Oito suites, também executáveis à mão
├── tools/                                          # Scripts de diagnóstico da API (só CLI) — ver tools/README.md
├── bin/
│   └── lint.php                                    # php -l sobre o plugin inteiro
├── .github/workflows/ci.yml                        # Lint 7.4→8.4, coding standards e testes
├── composer.json                                   # Dependências de desenvolvimento e scripts
├── phpcs.xml.dist                                  # WordPress Coding Standards + desvios documentados
├── phpunit.xml.dist
├── .editorconfig
├── CHANGELOG.md
├── LICENSE                                         # GPL v2
├── README.md
└── .gitignore
```

As pastas `tools/`, `tests/`, `bin/` e `.github/` não são usadas em execução e podem ser removidas numa instalação de produção, tal como `composer.json` e os ficheiros `*.dist`. Os scripts em `tools/` recusam qualquer coisa que não seja a linha de comandos, por isso não respondem a pedidos HTTP mesmo que fiquem lá.

### Desenvolvimento

As dependências são só de desenvolvimento; o plugin não precisa de `vendor/` para correr.

```bash
composer install
composer run check      # lint + coding standards + testes (o mesmo que o CI corre)
composer run lint       # php -l em todos os ficheiros
composer run cs         # relatório do PHP_CodeSniffer
composer run cs:fix     # corrige o que é mecanicamente corrigível
composer run test       # PHPUnit
```

Os testes não precisam de uma instalação do WordPress: cada suite declara os stubs de que precisa e corre no seu próprio processo. Também se executam à mão, uma a uma:

```bash
php tests/suites/change-detection.php
```

---

## Resolução de problemas

| Sintoma | Causa provável / solução |
|---|---|
| "Nenhum API Token configurado" | Introduza o token nas Configurações ou defina `RWBE_API_AUTH_TOKEN` no `wp-config.php` |
| Preços aparecem sem IVA | Configure as taxas do WooCommerce (ver [Configuração](#configuração)) |
| Barra de pesquisa sem marcas / modelos | Execute uma importação completa, ou use **Preencher a partir da API** |
| Menus mostram modelos de outra marca | A tabela de combinações ainda não atingiu 90% de cobertura — deixe o backfill terminar |
| Importação parou a meio | É retomada automaticamente; pode também usar o botão **Retomar** |
| Diagnóstico | Ative o Debug Log e consulte **RWBE Importer → Debug Log** (ou `wp-content/uploads/rwbe-logs/debug.log`) |

---

## Notas técnicas

- Compatível com **HPOS** (High-Performance Order Storage) do WooCommerce.
- Os produtos são finalizados via API do WooCommerce, garantindo que as tabelas de pesquisa (preço, stock, atributos) ficam sincronizadas — aparecem corretamente nos filtros e ordenação sem regravação manual.
- As opções do plugin são criadas com `autoload = no`, para não serem carregadas em todos os pedidos da loja.
- As caches dos menus são invalidadas por *namespace* de versão, sem deitar fora a cache de objetos de todo o site.
- **Melhoria futura:** mover a importação para o Action Scheduler, para lojas com volumes muito grandes.

---

## Histórico de versões

Registo completo em [CHANGELOG.md](CHANGELOG.md).

| Versão | Data | Destaque |
|---|---|---|
| **1.2.4** | 2026-10-01 | Desempenho (stock do cron em paralelo, catálogo já não é regravado sem motivo, filtro da loja por JOIN), segurança (AJAX com verificação de permissões, `tools/` só CLI, `uninstall.php`), WordPress Coding Standards, testes e CI |
| **1.2.3** | 2026-09-09 | Segurança: token da API deixou de existir em código — é introduzido manualmente |
| **1.2.2** | 2026-09-08 | Limpeza de placeholders corre no servidor e sobrevive ao fecho da página |
| **1.2.0** | 2026-09-04 | Tabela `wp_rwbe_fitment` com as combinações reais de veículo |
| **1.1.0** | 2026-09-04 | Mapa estático JSON dos menus Marca → Modelo → Ano (~5 ms em vez de ~310 ms) |
| **1.0.1** | 2026-09-01 | Limpeza de imagens placeholder duplicadas e lock de importação |
| **1.0.0** | 2026-08-05 | Primeira versão em uso |

---

## Licença

[GPL v2 ou posterior](LICENSE).

## Autor

**António Lopes** — [www.antoniolopes.io](https://www.antoniolopes.io)
