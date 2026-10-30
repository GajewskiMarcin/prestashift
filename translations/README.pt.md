<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>Módulo de migração PrestaShop</strong>
  <br>
  <strong>Simples · Elegante · Eficiente</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Baixar</a> ·
  <a href="#">Página do produto</a>
</p>

<p align="center">
  <a href="../README.md">English</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.it.md">Italiano</a> ·
</p>

> **Um único módulo:** ferramenta profissional de migração de dados para PrestaShop. Migre os dados da sua loja entre PrestaShop 1.7, 8 e 9 com total simplicidade.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Licença académica livre 3.0 (AFL-3.0)">
</p>

# O projeto PrestaShift é um módulo de migração de dados para PrestaShop.

**Em resumo:** permite migrar os dados de uma loja PrestaShop antiga (1.7+) para uma loja mais recente (8 ou 9), em lotes, com retomada, registo (logging) e pré-visualização.

Arquitetura em duas partes:

* Fonte (loja antiga) → módulo PSConnector (somente leitura, API segura por token)
* Destino (loja nova) → módulo PrestaShift (motor de migração)

## Funcionalidades

- **Pipeline de migração em 39 etapas** — produtos, categorias, clientes, pedidos, imagens, transportadoras, CMS e muito mais
- **Dois modos de ligação** — Bridge Connector (funciona entre servidores) ou Base de dados direta (mais rápida, mesmo servidor)
- **Adaptação às versões** — deteção automática das versões fonte/destino com avisos de compatibilidade
- **Transformações PS 1.7 → 8/9** — `redirect_type`, `id_type_redirected` convertidos automaticamente
- **Sincronização incremental** — migra apenas as alterações desde a última sincronização
- **Processamento em lotes** — tamanhos de lote configuráveis com pausa/retomada
- **Transferência de imagens** — descarrega as imagens e gera todos os tamanhos de miniatura
- **Suporte multiloja** — escolher o ID da loja destino
- **Verificações pré-voo** — valida os limites de PHP, espaço em disco e cURL antes de iniciar
- **Tarefas pós-migração** — reconstrução automática do índice de pesquisa, da árvore de categorias e limpeza da cache
- **Simulação em branco** — visualizar as quantidades de registos antes da migração
- **Registo em ficheiros** — registos detalhados em `var/logs/prestashift.log`
- **Mapa de redirecionamentos** — gera um ficheiro de redirecionamentos 301 para preservar o SEO
- **Mapeamento de estados** — associar os estados de encomenda entre a fonte e o destino
- **Idiomas da interface** — inglês, polaco, francês, espanhol, português, italiano, alemão
- **Configuração seletiva** — migra as definições seguras da loja (nome, SEO, envio, etc.)
- **Multi-idioma** — inglês + polaco (traduzível via o back-office PrestaShop)

## Dados migrados

| Domínio | Dados |
|---------|-------|
| **Catálogo** | Produtos, Categorias, Atributos, Características, Stock, Packs, Produtos virtuais, Campos de personalização, Etiquetas |
| **Tarificação** | Preços específicos, Regras de preço de catálogo |
| **Média** | Imagens de produto (com miniaturas), Anexos, Logotipos de fabricantes |
| **Clientes** | Clientes, Grupos, Moradas, Listas de desejos |
| **Encomendas** | Encomendas, Detalhes de encomenda, Histórico, Pagamentos, Faturas, Notas de crédito, Carrinhos |
| **Marcas** | Fabricantes, Fornecedores, Ligações Produto-Fornecedor |
| **Envio** | Transportadoras, Faixas, Zonas de entrega, Custos |
| **Conteúdo** | Páginas e categorias CMS, Metadados/SEO, Contactos, Lojas físicas |
| **Localização** | Países, Estados/regiões, Zonas, Moedas, Idiomas, Regras fiscais |
| **Administração** | Funcionários, Perfis, Regras de carrinho (com condições), Configuração da loja |
| **Stock** | Stock disponível, Movimentos de stock |

## Pré-requisitos

- **Loja destino:** PrestaShop 1.7+ (onde o PrestaShift está instalado)
- **Loja fonte:** PrestaShop 1.7+ (onde o PSConnector está instalado)
- PHP 7.4+ com a extensão cURL
- MySQL 5.7+ / MariaDB 10.3+

## Instalação

### Na loja DESTINO (loja nova):
1. Envie a pasta `prestashift/` para `/modules/`
2. Instale via o back-office → Módulos → « PrestaShift Migration »

### Na loja FONTE (loja antiga):
1. Envie a pasta `psconnector/` para `/modules/`
2. Instale via o back-office → Módulos → « PrestaShift Connector »
3. Copie o token seguro gerado a partir da página de configuração do módulo

### Iniciar a migração:
1. Abra o PrestaShift na loja destino
2. Introduza o URL da loja fonte + o token
3. Selecione o âmbito dos dados
4. Configure as opções (tamanho dos lotes, limpeza do destino, etc.)
5. Inicie a migração

## Arquitetura

```
Boutique source (PS 1.7/8)          Boutique cible (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (passerelle    │   Bridge  │   (moteur de      │
│    API en        │           │    migration)     │
│    lecture)      │           │                   │
└─────────────────┘            └──────────────────┘
```

O PSConnector expõe um ponto de entrada de API seguro e apenas de leitura. O PrestaShift liga-se a ele via HTTP, obtém os dados em lotes, transforma-os para compatibilidade entre versões e importa-os na base de dados destino.

Alternativa: ligação direta à base de dados (PDO) para migrações no mesmo servidor — mais rápida, sem passagem por gateway.

## Segurança

- Autenticação por token (hexadecimal de 64 caracteres)
- O conector é **apenas de leitura** — as operações de escrita são bloqueadas
- O acesso a ficheiros é restrito aos diretórios `img/`, `download/`, `upload/`
- Proteção contra traversal de caminhos com validação `realpath()`
- Comparação de token em tempo constante (`hash_equals`)

## Relatórios de erro (opt-in)

O módulo pode enviar por e-mail um relatório de falha ao autor, para ajudar a diagnosticar problemas de migração.
**Está desativado por predefinição** — ativa-o com o interruptor *Send Error Reports to Developer*
na etapa Opções.

Quando ativado, uma migração falhada envia: a mensagem de erro e a carga útil de depuração, as suas versões do PrestaShop e
do PHP, `memory_limit` / `max_execution_time`, o URL da loja fonte, a etapa em que a migração parou e as definições utilizadas (tamanhos dos lotes, tempos de espera, âmbito), o que a última chamada à
loja fonte fez, as últimas linhas do registo de migração, bem como o endereço de e-mail do empregado ligado. Nada é enviado enquanto o interruptor estiver desativado, e nunca são
transmitidos dados de clientes, encomendas ou catálogo. Ver [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — é
o único caminho de código que envia algo ao autor. A outra ligação de saída do módulo
(`ConnectorClient`) comunica apenas com o URL da loja fonte que configurar.

## Suporte

- [Reportar um problema](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discussions](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Ofereça-me um café](https://buymeacoffee.com/marcingajewski)

## Autor

- Criado por [marcingajewski.pl](https://marcingajewski.pl)

## Licença

[Licença académica livre 3.0 (AFL-3.0)](LICENSE) — a licença padrão dos módulos PrestaShop.

### Nota de atribuição

O seguinte é uma **Nota de Atribuição** ao abrigo da secção 6 da AFL-3.0. A secção 6 exige
que qualquer obra derivada a conserve:

> PrestaShift foi criado por Marcin Gajewski — https://marcingajewski.pl
> Projeto original: https://github.com/GajewskiMarcin/prestashift
> Apoiar o trabalho: https://buymeacoffee.com/marcingajewski
>
> As obras derivadas e os forks devem conservar esta nota, o crédito ao autor e o link de apoio exibidos
> no ecrã final do módulo. «PrestaShift» é o nome do módulo original: se publicar uma
> versão modificada, dê-lhe o seu próprio nome.

Os forks são bem-vindos — as correções enviadas através de pull requests beneficiam todos os utilizadores do módulo.

Contributions go through pull requests to `main`.

### Agradecimentos

O PrestaShift melhora com estas pessoas:

| Quem | Contribuição |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

A mesma lista está no módulo: **Definições → Sobre → Obrigado**.
