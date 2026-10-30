<p align="center">
  <a href="../AUDYT.md">English</a> ·
  <a href="AUDYT.fr.md">Français</a> ·
  <a href="AUDYT.de.md">Deutsch</a> ·
  <a href="AUDYT.es.md">Español</a> ·
  <a href="AUDYT.pt.md">Português</a> ·
  <a href="AUDYT.it.md">Italiano</a> ·
</p>

**# Auditoria PrestaShift**

Cada ponto segue a mesma estrutura: **o que indica a documentação do PrestaShop** (com o endereço da fonte), **o que o módulo faz** (ficheiro e linha), **veredito** e **ação**. Os pontos que não se baseiam nem na documentação nem no código do núcleo são assinalados como hipóteses e não são corrigidos enquanto não forem confirmados.

Fontes: documentação técnica do PrestaShop (`github.com/prestashop/docs`, branch 9.x), obtida através do context7, bem como o código do núcleo nos contentores de teste (1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

Estado: auditoria em curso, iniciada em 2026-10-21.

---

**## A1. Stock registado através da API do núcleo**

**Documentação.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`): tipo `action`,
localização `front office`, descrição: «Quantity is updated only when a customer
effectively places their order». FAQ sobre stock (`faq/stock.md`): «When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock.»

**O que fazia o módulo.** `ProductMigrationStep::importStock()` e
`ProductAttributeMigrationStep::importStock()` chamavam
`StockAvailable::setQuantity()` e `setProductOutOfStock()` para cada
registo. Esta chamada, com os argumentos predefinidos, regista um movimento
de stock (`StockManager::saveMovement()`) e aciona `actionUpdateQuantity`.

**Veredito.** Três problemas distintos:

1. Um movimento de stock para cada produto e combinação migrados. Confirmado
   por medição: na loja de destino, 4.439 produtos e 4.439 movimentos
   datados da migração, contra zero movimentos na loja de origem. A importação
   define uma quantidade absoluta; por isso, de acordo com a FAQ, deve registar
   diretamente o campo, e não uma variação.

2. O hook `actionUpdateQuantity` era acionado uma vez por produto: o código
   dos módulos de terceiros era, assim, executado durante a importação,
   contrariamente à descrição do hook («o cliente efetuou uma encomenda»).
   Na loja de teste, o módulo feedforge provocou um erro fatal nesse momento,
   o que teria interrompido a migração no cliente.

3. Custo: 147 ms por produto (medido no PS 9.2, 100 produtos), contra 0,22 ms
   com uma única escrita agrupada por lote. Isto representava a maior parte
   do tempo de execução do lote.

**Ação.** O novo ficheiro `src/Service/StockWriter.php` escreve em
`ps_stock_available` com um único `INSERT ... ON DUPLICATE KEY UPDATE` por lote,
utilizando todos os campos indicados pela documentação do webservice para esta
entidade (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), sem movimentos nem hooks. Integrado nas
duas etapas. Para verificação: `verify_stock.php` compara o stock de
cada produto e combinação com o da origem, utilizando a tabela de
correspondência dos IDs.

---

**## A2. Produtos assinalados como indexados quando, na realidade, não estão**

**Documentação.** Guia de migração (`basics/keeping-up-to-date/migration.md`):
«Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index«».
Descrição da tarefa cron de indexação (`development/products-lifecycle/search-index.md`):
o parâmetro `full=1` reconstrói todo o índice, `full=0` ou ausente —
«it will index only missing products». O comando
`prestashop:search:index --full` é descrito como demorado em catálogos
de grandes dimensões.

**O que fazia o módulo.** `ProductMigrationStep` escrevia
`'indexed' => 1` em `ps_product` e `ps_product_shop`, e o controlador das
tarefas finais executava `UPDATE ps_product SET indexed = 1` sem qualquer condição.
Entretanto, a reconstrução integrada do índice era ignorada para catálogos
com mais de 2.000 produtos.

**Veredito.** Num catálogo acima desse limite, nada era indexado,
embora todos os produtos tivessem `indexed = 1`. O método recomendado pela
documentação («Add missing products to the index») não detetava, por isso,
nenhum produto em falta: o utilizador clicava, recebia uma confirmação, mas
o motor de pesquisa continuava sem apresentar resultados. Confirmado por medição:
3.471 produtos, 3.475 com `indexed = 1` e 681 linhas em
`ps_search_index`, correspondentes exclusivamente aos produtos da loja original.

**Ação.** Os produtos migrados passam a receber `indexed = 0`, e o
`UPDATE` incondicional das tarefas finais foi eliminado: `Search::indexation()`
define o sinalizador para os produtos que efetivamente indexa.

**A decidir.** A mensagem apresentada quando a indexação é ignorada indica
atualmente «rebuild it in Shop Parameters > Search». Após esta correção,
a opção mais rápida e conforme à documentação é «Add missing products to
the index»; a mensagem deverá, por isso, ser alterada em conformidade. No entanto,
trata-se de uma alteração do texto apresentado ao utilizador, bem como de seis
ficheiros de tradução: aguarda validação.

---

**## A3. Encomendas: loja e estado — hipótese rejeitada**

**Suspeita.** `OrderMigrationStep::importOrder()` preenche o array
com `'id_shop' => $data['id_shop']` e `'current_state' => $data['current_state']`,
ou seja, com os valores da loja antiga. Se estes valores permanecessem inalterados,
as encomendas apontariam para uma loja e um estado inexistentes.

**Verificação.** A linha passa por `IdMapper::row('orders', ...)`, e o
mapeamento das colunas (`IdMapper.php:200`) traduz `id_shop` através
do dicionário `shop` (`SchemaHelper::getTargetShopId()`), `id_shop_group`
através de `shop_group` e `current_state` através de `order_state`, utilizando
a tabela de correspondência dos estados definida no assistente. O dicionário
`shop` está definido em `IdMapper.php:1078`.

**Veredito.** O funcionamento está correto, não existe qualquer erro neste ponto.
Este ponto permanece na auditoria para registar que foi verificado.

**A verificar em grande escala.** Após a migração, verificar se
`orders.current_state` corresponde à última entrada de `ps_order_history` —
isto exige uma execução com um grande volume de encomendas e será integrado
na medição em grande escala.

---

**## A4. Imagens: formatos e geração de miniaturas**

**Suspeita 1.** O módulo recupera da loja antiga apenas
`img/p/<caminho>/<id>.jpg`; uma loja configurada para PNG perderia, portanto,
as suas imagens.

**Verificação.** No núcleo, `ImageManager.php:154`: «Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY». O nome do ficheiro no disco continua a ser `<id>.jpg`,
independentemente da configuração; isto também é confirmado pelo conteúdo
da pasta `img/p` na loja de origem (408 ficheiros, todos em `.jpg`).
**Hipótese rejeitada.**

**Suspeita 2.** O PS 9 possui uma configuração distinta `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) para webp e avif, e o módulo
gera apenas miniaturas `.jpg`: uma loja que disponibilize imagens em webp
apresentaria, assim, espaços vazios.

**Verificação.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
verifica `file_exists()` e gera automaticamente a miniatura em falta no
formato solicitado. O mesmo comportamento existe no 1.7.8 (`ImageRetriever.php:182`)
e no 8.2 (`ImageRetriever.php:324`). **Hipótese rejeitada** para todas as
versões de destino.

**Conclusão contrária à esperada.** Uma vez que o núcleo gera por si próprio
as miniaturas em falta, gerar todos os formatos para cada imagem durante
a migração é um trabalho em grande parte desnecessário. Para 100.000 imagens
e cinco formatos, isso representa meio milhão de operações de redimensionamento
enquanto o utilizador aguarda. O PrestaShop 9.1 dispõe da sua própria ferramenta:
`php bin/console prestashop:thumbnails:regenerate products`
(documentação `development/components/console/prestashop-thumbnails-regenerate.md`),
e o painel de administração possui um botão de regeneração.

**Ação (acordo de 2026-10-21).** A geração de miniaturas foi
removida; o módulo guarda apenas o original. Esta decisão baseia-se em três
elementos:

1. O núcleo gera a miniatura em falta na primeira utilização, no
   front office (`ImageRetriever::checkOrGenerateImageType()`, o mesmo código
   no 1.7.8, 8.2 e 9.1) e no painel de administração
   (`ImageManager::thumbnail()`, que redimensiona o original para `img/tmp`,
   depois de verificar com `file_exists()` que o original existe).

2. Um comentário no nosso código afirmava que as miniaturas eram necessárias
   para que o painel de administração apresentasse a imagem. Isso é falso:
   o painel calcula-as a partir do original.

3. O módulo de referência faz o mesmo: `EDImport::copyImg()` executa
   `copy($tmpfile, $path . '.jpg')` e não gera quaisquer dimensões.

Custo eliminado: cinco operações de redimensionamento por imagem, ou seja,
meio milhão para um catálogo com 100.000 imagens. Quem pretender que tudo esteja
pronto imediatamente dispõe da opção «Regenerate thumbnails» no painel
de administração ou, a partir da versão 9.1, do comando
`php bin/console prestashop:thumbnails:regenerate products`.

---

**## A5. As palavras-passe dos clientes no formato antigo não funcionarão na nova loja**

**Documentação e código do núcleo.** Alterações da versão 8.0
(`modules/core-updates/8.0.md`): «`Customer::$passwd` to be
`Validate::isHashedPassword()`». Alterações da versão 8.1: «Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5». A implementação da verificação
(`src/Core/Crypto/Hashing.php`): `checkHash($passwd, $hash, staticSalt =
_COOKIE_KEY_)` utiliza dois métodos — `bcrypt` com
`password_verify(passwd, hash)` e `md5` com `md5(staticSalt . $passwd) ===
$hash`.

**Veredito.** As palavras-passe bcrypt são transferidas sem problemas, porque
a sua verificação não utiliza qualquer elemento específico da loja. As palavras-passe
no formato antigo md5 são protegidas com salt através da chave `COOKIE_KEY` da
loja que efetua a verificação; após a migração, deixarão, portanto, de ser
válidas: a nova loja possui uma chave diferente. Esta chave encontra-se em
`app/config/parameters.php`, e não na base de dados; o conector que lê a base
de dados não tem, por isso, acesso a ela e nenhuma migração a pode transferir.
Isto afeta as contas criadas no 1.5 e 1.6 cujos proprietários não voltaram
a iniciar sessão desde então — podem ser muito numerosas na loja antiga.
O módulo copiava a palavra-passe sem assinalar este problema.

**Ação (A5).** `CustomerMigrationStep::reportOldPasswords()` deteta as
palavras-passe no formato md5 (32 caracteres hexadecimais) e regista no
registo da migração um aviso que indica o número dessas contas no lote,
bem como a informação de que esses clientes terão de utilizar a função
«recuperar palavra-passe». As respetivas contas, encomendas e moradas são,
por sua vez, migradas normalmente. Não é possível corrigir este problema
de outra forma que não seja transferir a chave da loja, o que comprometeria
as contas já existentes na nova loja.

---

## A6. O conector aceitava mais do que apenas «leitura»

**O que fazia.** Em `psconnector/api.php`, a ação `query`
verificava a consulta através de uma lista negra de primeiras palavras
(`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) e só a rejeitava se a consulta
começasse por uma dessas palavras.

**Veredito.** Uma vulnerabilidade, e não era pequena. Tudo o que começasse por
`SELECT` passava pelo filtro:

- `SELECT ... INTO OUTFILE '/caminho/ficheiro.php'` — com o privilégio MySQL
  `FILE`, concedido por muitos serviços de alojamento partilhado, esta instrução
  grava qualquer ficheiro num local onde o utilizador da base de dados
  tenha permissão de escrita, incluindo um ficheiro PHP na pasta da loja.
  Trata-se de execução remota de código na loja antiga.

- `SELECT LOAD_FILE('/etc/passwd')` — leitura de qualquer ficheiro
  acessível ao MySQL.

- `SELECT SLEEP(30)`, `BENCHMARK(...)` — bloqueio do processo PHP durante
  um período de tempo arbitrário.

- Um comentário colocado antes da consulta (`/* */` ou `--`) ocultava a verdadeira
  primeira palavra.

A fuga do token do conector tinha, portanto, um valor muito superior ao de um
simples acesso de leitura aos dados.

**Ação.** A lista negra foi substituída por uma lista branca, porque o
módulo envia à origem apenas consultas `SELECT` (verificado: `SHOW COLUMNS`
em `SchemaHelper` é executado na loja de destino, e não através do
conector). Agora: os comentários são removidos antes da verificação,
a consulta tem de começar por `SELECT`, só é permitida uma instrução por
consulta, e `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`, `BENCHMARK`,
`SLEEP`, `GET_LOCK` e `RELEASE_LOCK` são rejeitados.

**Verificação.** `test_connector_guard.php` envia 11 consultas ao
conector real: três leituras normais (incluindo uma com JOIN e outra com
subconsulta) são aceites; uma escrita direta, uma escrita ocultada
por um comentário de bloco e um comentário de linha, duas instruções
na mesma consulta, a escrita de um ficheiro, a leitura de um ficheiro,
`SLEEP` e `BENCHMARK` são rejeitados. Os 11 resultados estão de acordo
com o esperado.

**Consequências para os utilizadores.** O conector foi atualizado
para a versão 1.3.0. A proteção atua do lado da loja antiga: protege
assim os utilizadores que instalam a nova versão do plugin na
loja de origem; o módulo funciona com ambas as versões.

---

## A7. Tarefas executadas uma única vez, num único pedido

**Suspeita.** O módulo divide o trabalho em lotes, mas executa quatro
operações uma única vez, num único pedido HTTP: a reconstrução da árvore
de categorias, a reconstrução do índice de pesquisa, as regras de preços
de catálogo e as consultas de correção `integrity`. Num catálogo de grandes
dimensões, cada uma destas operações pode ultrapassar o tempo máximo
da execução do pedido (30 s no ambiente de teste), fazendo com que a migração
falhe na última etapa.

**Medição.** Loja com 50.123 produtos e 3.122 categorias (base de dados
separada para não perturbar os outros testes):

| operação | duração |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` com uma única regra | 1,48 s |
| consultas de correção `integrity` (3 casos representativos) | menos de um segundo |
| `Search::indexation(true)` | mais de 10 minutos |

**Veredito.** As três primeiras operações não apresentam problemas,
mesmo com 50.000 produtos. A única operação dispendiosa é a reconstrução
completa do índice de pesquisa — e o módulo ignora-a acima dos 2.000 produtos,
deixando-a a cargo das ferramentas da loja. Após a correção A2 (os produtos
têm `indexed = 0`), o funcionamento corresponde exatamente ao que é recomendado
pela documentação de migração do PrestaShop. Este ponto fica, portanto, encerrado
sem alterações ao código.

**Método.** Esta conclusão exigiu três segundos de medição.
Anteriormente, previa uma execução de duas horas para 50.000 produtos, que
teria fornecido os mesmos dois dados. Princípio para o restante da auditoria:
medir o custo de um lote e de uma operação, em vez de processar milhões de linhas;
nenhum teste com duração superior a dez minutos sem uma necessidade claramente
justificada.

---