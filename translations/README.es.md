<p align="center">
  <img src="../assets/icon-256.png" alt="PrestaShift" title="PrestaShift" width="128" height="128">
</p>

<h1 align="center">PrestaShift</h1>

<p align="center">
  <strong>Módulo de migración de PrestaShop</strong>
  <br>
  <strong>Simple · Elegante · Eficaz</strong>
  <br>
  <a href="https://github.com/GajewskiMarcin/prestashift/releases">Descargar</a> ·
  <a href="#">Página del producto</a>
</p>

<p align="center">
  <a href="../README.md">English</a> ·
  <a href="README.fr.md">Français</a> ·
  <a href="README.de.md">Deutsch</a> ·
  <a href="README.es.md">Español</a> ·
  <a href="README.pt.md">Português</a> ·
  <a href="README.it.md">Italiano</a> ·
</p>

> **Un solo módulo:** herramienta profesional de migración de datos para PrestaShop. Migre los datos de su tienda entre PrestaShop 1.7, 8 y 9 con total sencillez.

<p align="center">
  <img src="https://img.shields.io/badge/PrestaShop-1.7%2B-DF0067?logo=prestashop&logoColor=white" alt="PrestaShop 1.7+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL 5.7+">
  <img src="https://img.shields.io/badge/MariaDB-10.3%2B-003545?logo=mariadb&logoColor=white" alt="MariaDB 10.3+">
  <img src="https://img.shields.io/badge/license-AFL--3.0-orange.svg" alt="Licencia académica libre 3.0 (AFL-3.0)">
</p>

# El proyecto PrestaShift es un módulo de migración de datos para PrestaShop.

**En resumen:** permite migrar los datos de una tienda PrestaShop antigua (1.7+) a una tienda más reciente (8 o 9), por lotes, con reanudación, registro y vista previa.

Arquitectura en dos partes:

* Origen (tienda antigua) → módulo PSConnector (solo lectura, API segura mediante token)
* Destino (tienda nueva) → módulo PrestaShift (motor de migración)

## Características

- **Pipeline de migración en 39 pasos** — productos, categorías, clientes, pedidos, imágenes, transportistas, CMS y mucho más
- **Dos modos de conexión** — Bridge Connector (funciona entre servidores) o Base de datos directa (más rápido, mismo servidor)
- **Adaptación a versiones** — detección automática de las versiones de origen/destino con avisos de compatibilidad
- **Transformaciones PS 1.7 → 8/9** — `redirect_type`, `id_type_redirected` se convierten automáticamente
- **Sincronización incremental** — migra solo los cambios desde la última sincronización
- **Procesamiento por lotes** — tamaños de lote configurables con pausa/reanudación
- **Transferencia de imágenes** — descarga las imágenes y genera todos los tamaños de miniaturas
- **Soporte multitienda** — elegir el ID de la tienda de destino
- **Verificaciones previas al vuelo** — valida los límites de PHP, el espacio en disco y cURL antes de iniciar
- **Tareas de posmigración** — reconstrucción automática del índice de búsqueda, del árbol de categorías y vaciado de la caché
- **Vista previa en seco** — visualizar el número de registros antes de la migración
- **Registro en archivos** — registros detallados en `var/logs/prestashift.log`
- **Mapa de redirecciones** — genera un archivo de redirecciones 301 para preservar el SEO
- **Mapeo de estados** — asociar los estados de pedido entre el origen y el destino
- **Idiomas de la interfaz** — inglés, polaco, francés, español, portugués, italiano, alemán
- **Configuración selectiva** — migra los ajustes seguros de la tienda (nombre, SEO, envíos, etc.)
- **Multi-idioma** — inglés + polaco (traducible desde el back-office de PrestaShop)

## Datos migrados

| Dominio | Datos |
|---------|-------|
| **Catálogo** | Productos, Categorías, Atributos, Características, Stock, Packs, Productos virtuales, Campos de personalización, Etiquetas |
| **Precios** | Precios específicos, Reglas de precio de catálogo |
| **Medios** | Imágenes de producto (con miniaturas), Archivos adjuntos, Logos de fabricantes |
| **Clientes** | Clientes, Grupos, Direcciones, Listas de deseos |
| **Pedidos** | Pedidos, Detalles de pedido, Historial, Pagos, Facturas, Abonos, Carritos |
| **Marcas** | Fabricantes, Proveedores, Enlaces Producto-Proveedor |
| **Envíos** | Transportistas, Rangos, Zonas de entrega, Costes |
| **Contenido** | Páginas y categorías CMS, Metadatos/SEO, Contactos, Tiendas físicas |
| **Localización** | Países, Estados/regiones, Zonas, Divisas, Idiomas, Reglas fiscales |
| **Administración** | Empleados, Perfiles, Reglas de carrito (con condiciones), Configuración de la tienda |
| **Stock** | Stock disponible, Movimientos de stock |

## Requisitos previos

- **Tienda de destino:** PrestaShop 1.7+ (donde se instala PrestaShift)
- **Tienda de origen:** PrestaShop 1.7+ (donde se instala PSConnector)
- PHP 7.4+ con la extensión cURL
- MySQL 5.7+ / MariaDB 10.3+

## Instalación

### En la tienda de DESTINO (tienda nueva):
1. Suba la carpeta `prestashift/` a `/modules/`
2. Instale desde el back-office → Módulos → « PrestaShift Migration »

### En la tienda de ORIGEN (tienda antigua):
1. Suba la carpeta `psconnector/` a `/modules/`
2. Instale desde el back-office → Módulos → « PrestaShift Connector »
3. Copie el token seguro generado desde la página de configuración del módulo

### Lanzar la migración:
1. Abra PrestaShift en la tienda de destino
2. Introduzca la URL de la tienda de origen + el token
3. Seleccione el alcance de datos
4. Configure las opciones (tamaño de los lotes, limpieza del destino, etc.)
5. Inicie la migración

## Arquitectura

```
Tienda origen (PS 1.7/8)          Tienda destino (PS 8/9)
┌─────────────────┐            ┌──────────────────┐
│   PSConnector    │◄──HTTP──► │   PrestaShift     │
│   (pasarela      │   Bridge  │   (motor de       │
│    API de solo   │           │    migración)     │
│    lectura)      │           │                   │
└─────────────────┘            └──────────────────┘
```

PSConnector expone un punto de entrada API seguro y de solo lectura. PrestaShift se conecta a él mediante HTTP, recupera los datos por lotes, los transforma para la compatibilidad entre versiones y los importa en la base de datos de destino.

Alternativa: conexión directa a la base de datos (PDO) para migraciones en el mismo servidor — más rápido, sin pasarela.

## Seguridad

- Autenticación mediante token (hexadecimal de 64 caracteres)
- El conector es **de solo lectura** — las operaciones de escritura están bloqueadas
- El acceso a archivos se restringe a los directorios `img/`, `download/`, `upload/`
- Protección contra el recorrido de directorios con validación `realpath()`
- Comparación de token en tiempo constante (`hash_equals`)

## Informes de error (opt-in)

El módulo puede enviar por correo electrónico un informe de fallo al autor, para ayudar a diagnosticar los problemas de migración.
**Está desactivado por defecto** — lo activa con el interruptor *Send Error Reports to Developer*
en el paso Opciones.

Cuando está activado, una migración fallida envía: el mensaje de error y la carga útil de depuración, sus versiones de PrestaShop y
PHP, `memory_limit` / `max_execution_time`, la URL de la tienda de origen, el paso en el que se detuvo la migración y los ajustes utilizados (tamaños de los lotes, plazos, alcance), lo que hizo la última llamada a la
tienda de origen, las últimas líneas del registro de migración, así como la dirección de correo electrónico del empleado conectado. No se envía nada mientras el interruptor esté desactivado, y nunca se transmiten datos de clientes, pedidos ni catálogo.
Vea [TelemetryService.php](prestashift/src/Service/TelemetryService.php) — es
la única ruta de código que envía algo al autor. La otra conexión saliente del módulo
(`ConnectorClient`) se comunica únicamente con la URL de la tienda de origen que usted configura.

## Soporte

- [Informar de un problema](https://github.com/GajewskiMarcin/prestashift/issues)
- [Discusiones](https://github.com/GajewskiMarcin/prestashift/discussions)
- [Invítame a un café](https://buymeacoffee.com/marcingajewski)

## Autor

Creado por [marcingajewski.pl](https://marcingajewski.pl)

## Licencia

[Licencia académica libre 3.0 (AFL-3.0)](LICENSE) — la licencia estándar de los módulos PrestaShop.

### Aviso de atribución

Lo siguiente es un **Aviso de atribución** en virtud de la sección 6 de la AFL-3.0. La sección 6 exige
que toda obra derivada lo conserve:

> PrestaShift fue creado por Marcin Gajewski — https://marcingajewski.pl
> Proyecto original: https://github.com/GajewskiMarcin/prestashift
> Apoyar el trabajo: https://buymeacoffee.com/marcingajewski
>
> Las obras derivadas y los forks deben conservar este aviso, el crédito al autor y el enlace de apoyo mostrados
> en la pantalla final del módulo. « PrestaShift » es el nombre del módulo original: si publica una
> versión modificada, por favor déle su propio nombre.

Los forks son bienvenidos — las correcciones enviadas mediante pull requests benefician a todos los usuarios del módulo.

Las contribuciones se envían mediante pull requests a `main`.

### Agradecimientos

PrestaShift mejora gracias a estas personas:

| Quién | Contribución |
|---|---|
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |
| @xxxxxx | xxxxxxx |

La misma lista está en el módulo: **Parámetros → Acerca de → Gracias**.
