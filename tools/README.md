# Scripts de diagnóstico

Ferramentas de linha de comandos usadas para inspecionar a API RWBE durante o
desenvolvimento. **Não são necessárias ao funcionamento do plugin** — pode
apagar esta pasta numa instalação de produção.

## Utilização

Todos leem o token da variável de ambiente `RWBE_API_TOKEN` (ou, quando o
WordPress é carregado, do token guardado nas Configurações). Nenhum contém
credenciais.

```bash
cd wp-content/plugins/rwbe-product-importer
RWBE_API_TOKEN=o-seu-token php tools/api-test.php
```

| Script | O que faz |
|---|---|
| `api-test.php` | Ligação básica à API e primeiros produtos |
| `standalone-api-test.php` | O mesmo, sem carregar o WordPress (só cURL) |
| `browser-api-test.php` | Versão com saída HTML, para abrir no browser |
| `api-response-test.php` | Mostra a estrutura completa da resposta da API |
| `api-application-test.php` | Inspeciona as aplicações de veículo (make / model / anos) |
| `api-product-images-test.php` | Imagens de um produto (destacada + galeria) |
| `api-gallery-test.php` | Endpoints de galeria |
| `brand-test.php` | Endpoints de marcas e logótipos |
| `txt-import-test.php` | Importação a partir de um ficheiro `test-products.txt` local |

Os que carregam o WordPress (`api-application-test.php`, `api-gallery-test.php`,
`api-product-images-test.php`, `brand-test.php`, `txt-import-test.php`) assumem a
localização normal do plugin, em `wp-content/plugins/rwbe-product-importer/tools/`.
