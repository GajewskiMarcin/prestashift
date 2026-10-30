<p align="center">
  <a href="../AUDYT.md">English</a> ·
  <a href="AUDYT.fr.md">Français</a> ·
  <a href="AUDYT.de.md">Deutsch</a> ·
  <a href="AUDYT.es.md">Español</a> ·
  <a href="AUDYT.pt.md">Português</a> ·
  <a href="AUDYT.it.md">Italiano</a> ·
</p>

# Auditoría de PrestaShift

Cada punto sigue la misma estructura: **lo que indica la documentación de PrestaShop** (con la dirección de la fuente), **lo que hace el módulo** (archivo y línea), **veredicto** y **acción**. Los puntos que no se basan ni en la documentación ni en el código del núcleo se señalan como hipótesis y no se corrigen hasta que hayan sido confirmados.

Fuentes: documentación técnica de PrestaShop (`github.com/prestashop/docs`, rama 9.x), obtenida mediante Context7, así como el código del núcleo en los contenedores de prueba (1.7.8.9, 8.2.4, 9.0.3, 9.1.0).

Estado: auditoría en curso, iniciada el 2026-10-21.

---

## A1. Stock registrado mediante la API del núcleo

**Documentación.** Hook `actionUpdateQuantity`
(`modules/concepts/hooks/list-of-hooks/actionUpdateQuantity.md`): tipo `action`,
ubicación `front office`, descripción: «Quantity is updated only when a customer
effectively places their order». FAQ sobre stock (`faq/stock.md`): «When you edit
the virtual quantity of a Product you are going to edit the
`StockAvailable::quantity` field directly in your database. When you modify your
stock through a `StockMovement` you apply a delta to your existing stock.»

**Lo que hacía el módulo.** `ProductMigrationStep::importStock()` y
`ProductAttributeMigrationStep::importStock()` llamaban a
`StockAvailable::setQuantity()` y `setProductOutOfStock()` para cada
registro. Esta llamada, con los argumentos predeterminados, registra un movimiento
de stock (`StockManager::saveMovement()`) y activa `actionUpdateQuantity`.

**Veredicto.** Tres problemas distintos:

1. Se registraba un movimiento de stock por cada producto y combinación migrados. Confirmado mediante medición: en la tienda de destino había 4.439 productos y 4.439 movimientos fechados en el momento de la migración, frente a cero movimientos en la tienda de origen. La importación establece una cantidad absoluta de stock; por tanto, de acuerdo con las FAQ, debe escribirse directamente el campo, no registrar una variación.

2. El hook `actionUpdateQuantity` se activaba una vez por producto: el código de los módulos de terceros se ejecutaba durante la importación, en contra de la descripción del hook («el cliente ha realizado un pedido»). En la tienda de prueba, el módulo feedforge provocó un error fatal en ese momento, lo que habría interrumpido la migración en la tienda del cliente.

3. Coste: 147 ms por producto (medido en PS 9.2, con 100 productos), frente a 0,22 ms utilizando una única escritura agrupada por lote. Esto representaba la mayor parte del tiempo de ejecución del lote.

**Acción.** El nuevo archivo `src/Service/StockWriter.php` escribe en
`ps_stock_available` mediante un único `INSERT ... ON DUPLICATE KEY UPDATE` por lote,
utilizando todos los campos indicados por la documentación del webservice para esta entidad (`id_product`,
`id_product_attribute`, `id_shop`, `id_shop_group`, `quantity`,
`depends_on_stock`, `out_of_stock`), sin movimientos ni hooks. Está integrado en
ambos pasos. Para verificarlo: `verify_stock.php` compara el stock de
cada producto y combinación con el de origen mediante la tabla de correspondencia de ID.

---

## A2. Productos marcados como indexados cuando en realidad no lo están

**Documentación.** Guía de migración (`basics/keeping-up-to-date/migration.md`):
«Imported products are not immediately available for search to your customers.
You must refresh the search index to make them available. Go to Shop parameters >
Search > Indexing and click on »Add missing product to the index«».
Descripción de la tarea cron de indexación (`development/products-lifecycle/search-index.md`):
el parámetro `full=1` reconstruye todo el índice, mientras que `full=0` o su ausencia significa:
«it will index only missing products». El comando
`prestashop:search:index --full` se describe como una operación larga en catálogos
grandes.

**Lo que hacía el módulo.** `ProductMigrationStep` escribía
`'indexed' => 1` en `ps_product` y `ps_product_shop`, y el controlador de las
tareas finales ejecutaba `UPDATE ps_product SET indexed = 1` sin ninguna condición.
Al mismo tiempo, se omitía la reconstrucción integrada del índice en los
catálogos de más de 2.000 productos.

**Veredicto.** En un catálogo que superaba ese límite, no se indexaba nada,
aunque todos los productos tenían `indexed = 1`. El método recomendado por la
documentación («Add missing products to the index») no detectaba entonces ningún
producto pendiente: el usuario hacía clic, recibía una confirmación, pero el
motor de búsqueda seguía sin devolver resultados. Confirmado mediante medición:
3.471 productos, 3.475 con `indexed = 1` y 681 filas en
`ps_search_index`, correspondientes únicamente a los productos de la tienda de origen.

**Acción.** Los productos migrados reciben `indexed = 0`, y se ha eliminado el
`UPDATE` incondicional de las tareas finales: `Search::indexation()`
establece el indicador para los productos que realmente indexa.

**Pendiente de decisión.** El mensaje que se muestra cuando se omite la
indexación indica actualmente «rebuild it in Shop Parameters > Search». Después de esta corrección,
la opción más rápida y conforme a la documentación es «Add missing products to
the index»; por tanto, el mensaje debería modificarse en consecuencia. Sin embargo,
se trata de un cambio de texto visible para el usuario, además de seis archivos de
traducción: está pendiente de aprobación.

---

## A3. Pedidos: tienda y estado — hipótesis descartada

**Sospecha.** `OrderMigrationStep::importOrder()` asigna en el array
`'id_shop' => $data['id_shop']` y `'current_state' => $data['current_state']`,
es decir, los valores de la tienda antigua. Si esto se mantuviera así, los pedidos
apuntarían a una tienda y a un estado inexistentes.

**Verificación.** La fila pasa por `IdMapper::row('orders', ...)`, y la
correspondencia de columnas (`IdMapper.php:200`) traduce `id_shop` mediante el
diccionario `shop` (`SchemaHelper::getTargetShopId()`), `id_shop_group` mediante
`shop_group` y `current_state` mediante `order_state`, es decir, utilizando la tabla
de correspondencia de estados definida en el asistente. El diccionario
`shop` está definido en `IdMapper.php:1078`.

**Veredicto.** El funcionamiento es correcto; no hay ningún error en este punto.
Se mantiene en la auditoría para dejar constancia de que se ha verificado.

**Pendiente de verificar a gran escala.** Después de la migración, comprobar que
`orders.current_state` corresponde a la última entrada de `ps_order_history`.
Esto requiere una ejecución con un volumen importante de pedidos y se incluirá
en las pruebas a gran escala.

---

## A4. Imágenes: formatos y generación de miniaturas

**Sospecha 1.** El módulo recupera de la tienda antigua únicamente
`img/p/<ruta>/<id>.jpg`; por tanto, una tienda configurada para utilizar PNG perdería sus imágenes.

**Verificación.** En el núcleo, `ImageManager.php:154`: «Desired file type **inside**
the image. If jpg and $forceType is false, format inside will be decided by
PS_IMAGE_QUALITY». El nombre del archivo en el disco sigue siendo `<id>.jpg`, independientemente
de la configuración. Esto también está confirmado por el contenido del directorio
`img/p` de la tienda de origen (408 archivos, todos en `.jpg`). **Hipótesis descartada.**

**Sospecha 2.** PS 9 dispone de un ajuste independiente `PS_IMAGE_FORMAT`
(`src/Core/Image/ImageFormatConfiguration.php`) para webp y avif, y el módulo
solo genera miniaturas `.jpg`: por tanto, una tienda que utilice webp mostraría
espacios vacíos.

**Verificación.** `src/Adapter/Image/ImageRetriever::checkOrGenerateImageType()`
comprueba `file_exists()` y genera sobre la marcha la miniatura que falta, en el
formato solicitado. El mismo comportamiento existe en 1.7.8 (`ImageRetriever.php:182`)
y 8.2 (`ImageRetriever.php:324`). **Hipótesis descartada** para todas las
versiones de destino.

**Conclusión contraria a lo esperado.** Puesto que el núcleo genera por sí mismo
las miniaturas que faltan, generar todos los formatos para cada imagen durante
la migración supone un trabajo en gran medida innecesario. Para 100.000 imágenes
y cinco formatos, esto representa medio millón de operaciones de redimensionamiento mientras
el usuario espera. PrestaShop 9.1 dispone de su propia herramienta:
`php bin/console prestashop:thumbnails:regenerate products`
(documentación `development/components/console/prestashop-thumbnails-regenerate.md`),
y el panel de administración tiene un botón de regeneración.

**Acción (acuerdo del 2026-10-21).** Se ha eliminado la generación de miniaturas;
el módulo guarda únicamente el original. Esta decisión se basa en tres elementos:

1. El núcleo genera la miniatura que falta cuando se utiliza por primera vez, tanto
en el front office (`ImageRetriever::checkOrGenerateImageType()`, mismo código en
1.7.8, 8.2 y 9.1) como en el panel de administración
(`ImageManager::thumbnail()`, que redimensiona el original hacia `img/tmp`,
después de comprobar con `file_exists()` que el original existe).

2. Un comentario en nuestro código afirmaba que las miniaturas eran necesarias
para que el panel de administración mostrara la imagen. Es falso: el panel
las calcula a partir del original.

3. El módulo de referencia hace lo mismo: `EDImport::copyImg()` ejecuta
`copy($tmpfile, $path . '.jpg')` y no genera ninguna dimensión.

Coste eliminado: cinco operaciones de redimensionamiento por imagen, es decir,
medio millón para un catálogo de 100.000 imágenes. Quienes quieran que todo esté
preparado inmediatamente disponen de «Regenerate thumbnails» en el panel
de administración o, a partir de 9.1, de
`php bin/console prestashop:thumbnails:regenerate products`.

---

## A5. Las contraseñas de los clientes en el formato antiguo no funcionarán en la nueva tienda

**Documentación y código del núcleo.** Cambios de la versión 8.0
(`modules/core-updates/8.0.md`): «`Customer::$passwd` to be
`Validate::isHashedPassword()`». Cambios de la versión 8.1: «Introduced
`PrestaShop\PrestaShop\Core\Security\Hashing` class to hash a password from a
plain text and a salt with md5». La implementación de la verificación
(`src/Core/Crypto/Hashing.php`): `checkHash($passwd, $hash, staticSalt =
_COOKIE_KEY_)` utiliza dos métodos: `bcrypt` con
`password_verify(passwd, hash)` y `md5` con `md5(staticSalt . $passwd) ===
$hash`.

**Veredicto.** Las contraseñas bcrypt se transfieren sin problemas, porque
su verificación no utiliza ningún elemento propio de la tienda. Las contraseñas
en el formato antiguo md5 utilizan como salt la clave `COOKIE_KEY` de la
tienda que realiza la verificación; por tanto, después de la migración dejarán
de ser válidas: la nueva tienda tiene otra clave. Esta clave se encuentra
en `app/config/parameters.php`, no en la base de datos; por tanto, el conector
que lee la base de datos no tiene acceso a ella y no puede transferirla durante
la migración.

Esto afecta a las cuentas creadas con las versiones 1.5 y 1.6 cuyos propietarios
no han vuelto a iniciar sesión desde entonces; pueden ser muy numerosas en la tienda antigua.
El módulo copiaba la contraseña sin advertir de este problema.

**Acción (A5).** `CustomerMigrationStep::reportOldPasswords()` detecta las
contraseñas en formato md5 (32 caracteres hexadecimales) y registra en el informe
de migración una advertencia con el número de estas cuentas en el lote,
así como la información de que esos clientes tendrán que utilizar la función
«He olvidado mi contraseña». Sus cuentas, pedidos y direcciones se migran
con normalidad. No es posible solucionar este problema de otra manera que transfiriendo
la clave de la tienda, lo que pondría en riesgo las cuentas que ya existen en la
nueva tienda.

---

## A6. El conector aceptaba más operaciones que las de «solo lectura»

**Lo que hacía.** En `psconnector/api.php`, la acción `query`
comprobaba la consulta mediante una lista negra de primeras palabras
(`INSERT`, `UPDATE`, `DELETE`, `DROP`, …) y solo la rechazaba si la consulta
comenzaba por una de esas palabras.

**Veredicto.** Una vulnerabilidad, y no precisamente pequeña. Todo lo que comenzaba por
`SELECT` superaba este filtro:

- `SELECT ... INTO OUTFILE '/ruta/archivo.php'` — con el privilegio MySQL
  `FILE`, concedido por muchos alojamientos compartidos, esta instrucción
  guarda cualquier archivo en una ubicación en la que el usuario de la base de datos
  tenga permiso de escritura, incluido un archivo PHP en el directorio de la tienda.
  Esto permite ejecutar código de forma remota en la tienda antigua.

- `SELECT LOAD_FILE('/etc/passwd')` — lectura de cualquier archivo
  accesible para MySQL.

- `SELECT SLEEP(30)`, `BENCHMARK(...)` — bloqueo del proceso PHP durante
  un tiempo arbitrario.

- Un comentario colocado antes de la consulta (`/* */` o `--`) ocultaba la verdadera
  primera palabra.

Por tanto, la filtración del token del conector permitía mucho más que un simple
acceso de lectura a los datos.

**Acción.** La lista negra se ha sustituido por una lista blanca, ya que el
módulo solo envía consultas `SELECT` al origen (verificado: `SHOW COLUMNS`
en `SchemaHelper` se ejecuta en la tienda de destino, no a través del
conector). Ahora: los comentarios se eliminan antes de la comprobación,
la consulta debe comenzar por `SELECT`, solo se permite una instrucción por
consulta y se rechazan `INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`, `BENCHMARK`,
`SLEEP`, `GET_LOCK` y `RELEASE_LOCK`.

**Verificación.** `test_connector_guard.php` envía 11 consultas al
conector real: se aceptan tres lecturas normales (incluidas una con JOIN y otra con
subconsulta); se rechazan una escritura directa, una escritura oculta detrás de un
comentario de bloque y de un comentario de línea, dos instrucciones en una misma
consulta, la escritura de un archivo, la lectura de un archivo,
`SLEEP` y `BENCHMARK`. Los 11 resultados son conformes a lo esperado.

**Consecuencia para los usuarios.** El conector se ha actualizado
a la versión 1.3.0. La protección actúa en el lado de la tienda antigua: protege
por tanto a los usuarios que instalan la nueva versión del plugin en la
tienda de origen; el módulo funciona con ambas versiones.

---

## A7. Tareas ejecutadas una sola vez, en una única petición

**Sospecha.** El módulo distribuye el trabajo en lotes, pero ejecuta cuatro
operaciones una sola vez, en una única petición HTTP: la reconstrucción del árbol
de categorías, la reconstrucción del índice de búsqueda, las reglas de precios del catálogo
y las consultas de corrección `integrity`. En un catálogo grande, cualquiera
de ellas podría superar el tiempo máximo de ejecución de la petición
(30 s en el alojamiento del proveedor), en cuyo caso la migración fallaría en la última etapa.

**Medición.** Tienda con 50.123 productos y 3.122 categorías (base de datos
independiente para no interferir con las demás pruebas):

| Operación | Duración |
|---|---|
| `Category::regenerateEntireNtree()` | 0,13 s |
| `SpecificPriceRule::applyAllRules()` con una sola regla | 1,48 s |
| Consultas de corrección `integrity` (3 casos representativos) | menos de un segundo |
| `Search::indexation(true)` | más de 10 minutos |

**Veredicto.** Las tres primeras operaciones no plantean ningún problema,
incluso con 50.000 productos. La única operación costosa es la reconstrucción
completa del índice de búsqueda, y el módulo la omite cuando hay más de 2.000 productos,
dejándola en manos de las herramientas de la tienda. Después de la corrección A2 (los productos
tienen `indexed = 0`), funciona exactamente como recomienda la
documentación de migración de PrestaShop. Por tanto, este punto queda cerrado sin modificar
el código.

**Método.** Esta conclusión requirió tres segundos de medición.
Anteriormente, estaba previsto realizar una ejecución de dos horas con 50.000 productos,
que habría proporcionado las mismas dos cifras. Principio para el resto de la auditoría: medir
el coste de un lote y de una operación, en lugar de procesar millones de líneas;
ninguna prueba de más de diez minutos sin una necesidad claramente justificada.

---
