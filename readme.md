# WPOptimizing

Contributors: artprojectgroup

Tags: rendimiento, limpieza, base de datos, caché, optimización

Requires at least: 6.6

Tested up to: 7.1

Requires PHP: 7.4

Stable tag: 0.1.0

License: GNU General Public License v3 or later

License URI: https://www.gnu.org/licenses/gpl-3.0.html

Acelera WordPress con una caché de página, una caché de objetos y mejoras en el front-end, y limpia los restos sabiendo de qué plugin o tema es cada uno.

## Description

**WPOptimizing** mide dónde se va el tiempo, acelera el sitio (caché de página, caché de objetos, deduplicación de consultas, autoload, CSS y JavaScript, imágenes, carga y entrega) y limpia lo que sobra, sabiendo *de quién* es cada registro de la base de datos y cada archivo antes de que borres nada. Su caché de página viene apagada y sólo sirve copias a los visitantes anónimos: los usuarios con sesión, los carritos, el pago, los formularios que ponen una cookie, las búsquedas y el escritorio reciben siempre una página recién generada, y cada cambio en el contenido purga las páginas donde aparece.

La mayoría de los limpiadores borran a ciegas. WPOptimizing atribuye cada opción, metadato de entrada, usuario o término, tipo de contenido, taxonomía, shortcode, widget, tarea de cron, acción de Action Scheduler y archivo a su plugin o tema con un sistema de confianza de varios niveles, así que siempre sabes qué borras y por qué.

### Atribución y análisis

- **Detección de huérfanos con atribución** en la base de datos y en el sistema de archivos, con un nivel de confianza para cada elemento en lugar de suposiciones.
- **Analizador de código estático** que lee el código de cada plugin y tema y resuelve constantes, variables y concatenaciones, de modo que atribuye las claves incluso antes de que existan los datos.
- **Mapa en tiempo real**: un plugin obligatorio (mu-plugin) muy ligero apunta qué extensión registra cada elemento mientras se ejecuta, sin coste apreciable cuando no cambia nada.
- **Conjunto de datos compartido opcional** (hay que activarlo, es recíproco y viene apagado): sólo se intercambian nombres, tamaños y frecuencias, nunca valores ni contenido. Ver *Servicios externos* más abajo.
- **Atribución manual**: dile a WPOptimizing quién es el dueño real de un elemento cuando la atribución automática se equivoca o no existe; tu regla manda sobre cualquier fuente automática.
- **Resultados del análisis** en una sola pantalla, con filtro por estado (Por identificar, Huérfanos, Inactivos, Vacíos y En uso), cada lista completa, con buscador y paginada.
- **Interruptor de emergencia**: `define( 'WPOPT_DISABLE', true );` en `wp-config.php` detiene todo lo que se carga antes que el plugin (los drop-ins y el mu-plugin) sin FTP, sin tocar la base de datos y sin desactivarlo.

### Limpieza

- **Borra lo que atribuye el análisis**: opciones, metadatos, tablas, archivos, tareas de cron y registros vacíos huérfanos, y **desinstala restos de extensiones**, de una en una, siempre con copia previa que se puede restaurar desde la pestaña Copias de seguridad.
- **Limpieza general**: revisiones, borradores automáticos, entradas en la papelera, comentarios spam, pendientes o en la papelera, pingbacks y trackbacks, transitorios caducados (o todos), cachés de oEmbed, etiquetas sin usar, metadatos huérfanos y duplicados, registros de Action Scheduler y optimización de tablas.
- **Perfiles rápidos** (Seguro, Blog, WooCommerce, Membresía y Agencia) que aplican ajustes de limpieza, rendimiento y front-end con un clic, desde Ajustes o desde el asistente de bienvenida.
- **Vista previa sin borrar nada**, registros detallados e informe imprimible.
- **Copia antes de tocar nada**: los borrados de huérfanos, autoload, filas vacías, cron y medios, la limpieza general, las desinstalaciones y los cambios en .htaccess y wp-config.php hacen copia antes y se pueden restaurar desde Copias de seguridad; si la copia no se puede escribir, no se cambia nada. Los tipos de contenido y las taxonomías nunca se borran solos.

### Rendimiento

- **Caché de página** (hay que activarla; sólo en sitios simples): sirve a los visitantes anónimos una copia estática de cada página a través de `advanced-cache.php`, así que la mayoría de las visitas ni siquiera arrancan WordPress. Lleva exclusiones de seguridad fijas que no se pueden quitar (usuarios con sesión, sesiones, carritos y pago, el escritorio, REST, AJAX, feeds, búsquedas y cualquier página que ponga una cookie o pida que no se guarde) y las tuyas propias por URL, cookie, navegador, parámetro y tipo de página. Las páginas se purgan cuando cambia el contenido que muestran, se rehacen cada 1 a 12 horas (dentro de la vida de los tokens de seguridad de WordPress), se guardan comprimidas con gzip, se sirven un momento caducadas mientras una sola petición las rehace, se separan por moneda e idioma y, si quieres, por dispositivo. Incluye el botón *Purgar esta página* en la barra superior, mantiene WP-Cron en marcha en los sitios en caché y se niega a funcionar junto a otra caché de página o a una caché del alojamiento que no puede vaciar. No está disponible en redes multisitio.
- **Caché de objetos persistente** (Redis, Memcached, APCu o SQLite integrado) con analítica de aciertos; respeta las constantes WP_REDIS_* y nunca sirve datos viejos tras una caída.
- **Deduplicación de consultas**, un drop-in que junta las lecturas idénticas de una misma petición y atribuye las consultas duplicadas al plugin o tema responsable.
- **Diagnóstico de la base de datos y de Redis** que sólo enseña lo que hay que arreglar, cada cosa con el ajuste que lo arregla.
- **Gestión del autoload**: el peso de cada opción autocargada y de quién es, desactivar las pesadas y la desactivación y activación inteligentes, todo reversible.
- **Tablas**: motor, tamaño y espacio recuperable, OPTIMIZE y conversión de MyISAM a InnoDB.
- **Cron**: detecta y quita las tareas programadas huérfanas de plugins desinstalados.
- **Minificación de CSS y JavaScript** (hay que activarla), con modo de prueba y exclusiones.
- **Rendimiento del front-end** (hay que activarlo): Speculative Loading, prioridad para la imagen LCP y carga diferida con exclusiones, indicaciones de recursos y un diagnóstico Server-Timing.
- **Control de Heartbeat** por zona (escritorio, editor y parte pública) y recuentos de comentarios del escritorio en caché.
- **Convivencia con las cachés del alojamiento y de otros plugins**: detecta WP Rocket, LiteSpeed, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, Kinsta, Varnish y muchas más, y las vacía después de una limpieza y cada vez que cambia el contenido (WP Engine, Kinsta, SiteGround, Cloudways, Pressable, Nginx Helper, RunCloud, GridPane y Pantheon), además de un botón *Vaciar todas las cachés*. Lo que dejó un plugin de caché antiguo (reglas en .htaccess, su advanced-cache.php, una segunda línea WP_CACHE) se limpia con copia previa.
- **Importar desde otro plugin**: trae los ajustes de WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Perfmatters, FlyingPress, Breeze, WP-Optimize, Powered Cache, Hummingbird y Autoptimize, con vista previa y deshacer en un clic; nunca copia credenciales ni enciende nada que allí estuviera apagado.
- **Purga de Cloudflare** con tu propio token de API: todo al vaciar las cachés y sólo las páginas afectadas cuando cambia el contenido.
- **Caché del navegador y compresión** mediante un bloque gestionado de .htaccess.
- **Historial**: métricas de la referencia frente a ahora con minigráficos, y puntuaciones de PageSpeed Insights y Chrome UX Report bajo demanda.

### Medios

- Archivos y miniaturas huérfanos y adjuntos que quizá no se usan, que se pueden borrar siempre con copia previa, y el recuento de imágenes duplicadas con el mismo contenido.
- **Imágenes**: reduce las subidas demasiado grandes (con el mismo sistema de WordPress, que conserva el original) y sirve en la parte pública las copias WebP o AVIF que ya tenga cada imagen, con el original como respaldo.

### Herramientas y escala

- **Multisitio**: cada sitio analiza y limpia sus propios datos. Pestaña **Documentación** dentro del plugin y menú en la barra superior (analizar, limpiar y vaciar).
- **Ayuda contextual** en cada pantalla, en la pestaña Ayuda nativa de WordPress: un panel por pestaña y sección de ajustes, cada uno enlazado a su apartado de la documentación, en español o en inglés.
- **Pensado para bases de datos enormes**: el análisis se ejecuta en segundo plano y por etapas, cambia a consultas aproximadas rápidas en las instalaciones muy grandes y limita el tiempo de cada consulta.

### Sistema de confianza de 7 niveles

- **Manual**: lo has dicho tú. Manda sobre cualquier fuente automática.
- **Seguro**: capturado en directo en tu sitio, por un plugin a cuyo espacio de nombres pertenece el elemento.
- **Probable**: comparación de instantáneas entre los estados activo e inactivo.
- **Posible**: leído en el propio código de la extensión, o confirmado por varios sitios en el conjunto de datos compartido.
- **Dudoso**: una sola reclamación, fuera del espacio de nombres de quien reclama. Revísalo antes de borrar.
- **Estimado**: sólo coincide un patrón de prefijo. Revísalo antes de borrar.
- **Indeterminado**: no hay atribución posible. No lo borres nunca sin revisarlo.

WPOptimizing Pro, que se vende aparte en [wpoptimizing.com](https://wpoptimizing.com), añade a la caché de página el servido sin PHP, la precarga, Brotli, la minificación del HTML, el diagnóstico de aciertos y las páginas en el borde de Cloudflare, y además Smart Assets, el retraso de JavaScript, el CSS crítico, la optimización de imágenes, la auditoría de la biblioteca de medios, la limpieza programada, el borrado en bloque y herramientas para agencias. Este plugin funciona completo por sí solo.

### Requisitos

- WordPress 6.6 o superior
- PHP 7.4 o superior
- MySQL 5.7 o MariaDB 10.3 o superior
- Permiso de escritura en wp-content/wpoptimizing/ y wp-content/mu-plugins/

### Idiomas

Inglés y traducciones completas al español (es_ES), alemán (de_DE), francés (fr_FR), italiano (it_IT), portugués de Portugal (pt_PT), ruso (ru_RU), árabe (ar) y chino simplificado (zh_CN).

### Créditos

- [Plus Jakarta Sans](https://github.com/tokotype/PlusJakartaSans), de Tokotype: SIL Open Font License 1.1 (assets/fonts/PlusJakartaSans-OFL.txt).
- [Genericons](https://github.com/Automattic/Genericons), de Automattic: GPL-2.0 o posterior.
- [matthiasmullie/minify](https://github.com/matthiasmullie/minify) y [matthiasmullie/path-converter](https://github.com/matthiasmullie/path-converter), de Matthias Mullie: MIT.
- [SelectWoo](https://github.com/woocommerce/selectWoo), de WooCommerce, basado en Select2: MIT.

### Documentación

- En español: [artprojectgroup.es/documentacion/wpoptimizing](https://artprojectgroup.es/documentacion/wpoptimizing)
- En inglés: [artprojectgroup.com/documentation/wpoptimizing](https://artprojectgroup.com/documentation/wpoptimizing)

## Servicios externos

WPOptimizing funciona sin ninguno de ellos. Cada uno sólo se contacta cuando se usa la función que lo necesita, y siempre por HTTPS.

1. **Pasarela de atribución de WPOptimizing** (https://wpoptimizing.com), sólo si activas el intercambio de datos (*Ayudar a mejorar la precisión de la atribución*, en el asistente de bienvenida o en *Ajustes › Conjunto de datos compartido*). Viene apagado y, mientras lo esté, nunca se contacta con la pasarela.
   - Qué se envía: el dominio de tu sitio (para registrar la instalación y obtener un token propio) y los nombres de las opciones, metadatos, tablas y archivos, sus tamaños y cada cuánto aparecen, el slug del plugin o tema dueño de cada uno, y los identificadores y rutas relativas de los CSS y JS que cargan tus páginas. Nunca valores de opciones, contenido de entradas o usuarios, ni el contenido de ningún registro o archivo.
   - Cuándo: a diario y después de cada análisis (envío de esos metadatos y descarga del conjunto compartido), cuando añades una regla de atribución manual (se envía la regla), cuando usas el asistente de atribución (se envían los nombres de lo que consultas) y una vez más al desactivar el plugin.
   - Condiciones: https://wpoptimizing.com/legal-notice. Privacidad: https://wpoptimizing.com/privacy-policy
2. **API de Google PageSpeed Insights** (https://www.googleapis.com/pagespeedonline/), sólo cuando pulsas para lanzar una prueba.
   - Qué se envía: la URL pública de la página que se prueba y, si la has puesto, tu clave de API de Google.
   - Condiciones: https://policies.google.com/terms. Privacidad: https://policies.google.com/privacy
3. **API de Chrome UX Report** (https://chromeuxreport.googleapis.com), sólo cuando pides el historial de datos reales y has puesto una clave de API de Google.
   - Qué se envía: el origen público de tu sitio y tu clave de API de Google.
   - Condiciones: https://policies.google.com/terms. Privacidad: https://policies.google.com/privacy
4. **API de Cloudflare** (https://api.cloudflare.com), sólo cuando pones un ID de zona y tu propio token en *Caché › Cloudflare*.
   - Qué se envía: el ID de zona, el token (como cabecera de autorización) y las URL que hay que purgar, o una petición para purgarlo todo al vaciar las cachés.
   - Condiciones: https://www.cloudflare.com/website-terms/. Privacidad: https://www.cloudflare.com/privacypolicy/
5. **API del directorio de plugins y temas de WordPress.org** (https://api.wordpress.org), la misma API pública que usa WordPress para mostrar y actualizar plugins y temas.
   - Qué se envía: slugs de plugins y temas. Durante un análisis, los de extensiones que ya no están instaladas pero dejaron datos, para enseñar su nombre en lugar del slug; y cuando asignas un dueño a mano, el texto que escribes, para encontrar plugins que coincidan. Nada del contenido de tu sitio.
   - Condiciones: https://wordpress.org/about/. Privacidad: https://wordpress.org/about/privacy/

## Installation

1. Sube la carpeta wpoptimizing a /wp-content/plugins/, o instálalo desde *Plugins › Añadir nuevo*.
2. Activa el plugin en la pantalla *Plugins*.
3. Abre WPOptimizing en el menú del escritorio; el asistente de bienvenida te ayuda a elegir un perfil y las opciones que quieras.
4. Pulsa *Analizar ahora* para el primer análisis.
5. Revisa los resultados, empezando por la lista de Huérfanos.

## Frequently Asked Questions

### ¿Es seguro borrar los elementos de confianza baja (Estimado o Indeterminado)?

No automáticamente. Estimado significa que la atribución se basa en un prefijo, e Indeterminado, que no se encontró ninguna. Sólo los elementos Manual, Seguro, Probable y Posible se ofrecen para la limpieza automática; el resto hay que revisarlo antes de borrar. Los borrados atribuidos y la limpieza general hacen antes una copia que se restaura desde la pestaña Copias de seguridad.

### ¿Guarda en caché mis páginas? ¿Choca con mi alojamiento o con mi plugin de caché?

Sólo si activas su caché de página, en *Caché › Caché de página*; viene apagada. Entonces guarda una copia de cada página sólo para los visitantes anónimos: los usuarios con sesión, las sesiones de WooCommerce y EDD, los carritos, el pago y las páginas de cuenta, el escritorio, las búsquedas y cualquier página que ponga una cookie o pida que no se guarde nunca se cachean, así que nunca se sirve un formulario o un carrito de la copia de otra persona. Las páginas se rehacen dentro de la vida de los tokens de seguridad de WordPress y se purgan cuando cambia su contenido.

No funciona junto a otra caché de página: si WP Rocket, LiteSpeed Cache, W3 Total Cache o tu alojamiento ya guardan las páginas, WPOptimizing no enciende la suya y vacía la de ellos después de cada limpieza. La caché de objetos y lo demás conviven con cualquiera de ellos.

### ¿Hará más lento un sitio muy grande?

El análisis se ejecuta en segundo plano y por etapas, así que nunca bloquea la visita de nadie ni se corta por tiempo. En bases de datos muy grandes usa consultas aproximadas rápidas, limita el tiempo de cada consulta y muestrea su instrumentación, así que el sitio en marcha está protegido.

### ¿Es compatible con WooCommerce y HPOS?

Sí. WPOptimizing declara compatibilidad con el almacenamiento de pedidos de alto rendimiento (HPOS) y con los bloques del carrito y del pago, nunca lee ni escribe directamente los pedidos y sólo borra tipos de contenido y taxonomías que ya no están registrados.

### ¿Funciona en WordPress Multisitio?

Sí. Cada sitio analiza, revisa y limpia sus propios datos por separado. La caché de página no está disponible en redes multisitio.

### ¿Dónde se guardan las copias de seguridad?

En el propio servidor, en wp-content/wpoptimizing/backups/.

### ¿Envía mis datos a algún sitio?

No, salvo que actives el conjunto de datos compartido, y aun así sólo nombres, tamaños y frecuencias, nunca valores ni contenido. En *Servicios externos* tienes cada servicio que el plugin puede contactar y cuándo.

## Changelog

### 0.1.0

- Primera versión.
