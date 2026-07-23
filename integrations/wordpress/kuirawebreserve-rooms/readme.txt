=== KuiraWebReserve Habitaciones ===
Contributors: kuirawebreserve
Tags: hotel, habitaciones, precios, reservas
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.2.0
License: GPL-2.0-or-later

Muestra tus tipos de habitacion con foto, precio y amenidades EN VIVO
desde KuiraWebReserve, e incrusta el wizard de reservas completo.

== Description ==
Agrega a tu sitio WordPress una cuadricula con tus tipos de habitacion:
foto, nombre, descripcion, capacidad, amenidades y precio "desde". El
precio SIEMPRE se consulta a tu sistema (con cache de 5 minutos) — nunca
se copia ni se guarda en WordPress. Cambias la tarifa en tu panel de
KuiraWebReserve y tu sitio se actualiza solo.

* Shortcode `[kuirawebreserve_rooms]` (opcional: `columns="2"`) —
  tarjetas responsivas con foto y precio vivo
* Shortcodes `[kuira_reservas]`, `[kuira_experiencias]` y
  `[kuira_grupos]` — el wizard completo incrustado (fechas,
  disponibilidad, extras y pago en linea)
* Boton "Probar conexion" en Ajustes: corrobora dominio y token al
  momento y te dice exactamente que falta si algo falla
* Si tu sistema no responde, el sitio sigue mostrando las ultimas
  tarjetas buenas (respaldo de 12 horas) — nunca una pagina vacia
* Consulta el catalogo desde el SERVIDOR de WordPress: el token nunca
  viaja al navegador del visitante
* Boton "Reservar" con destino automatico a tu pagina de reservas en
  linea (o la URL que tu pongas: WhatsApp, telefono...)
* Sin dependencias, sin jQuery, sin cuentas extra

== Installation ==
1. En tu panel de KuiraWebReserve, ve a **Integracion** y pulsa
   "Conectar sitio". Copia el token que se muestra (solo se ve una vez).
2. Sube la carpeta `kuirawebreserve-rooms` a `/wp-content/plugins/`
   (o instala el zip) y actívala.
3. Ve a **Ajustes → KuiraWebReserve Habitaciones**: pega el dominio de tu
   hotel (ej. `mihotel.kuirawebreserve.com`, sin "https://" ni rutas) y
   el token. Pulsa **Probar conexion** para corroborar.
4. Pega el shortcode `[kuirawebreserve_rooms]` donde quieras las
   tarjetas, o `[kuira_reservas]` donde quieras el wizard completo.

== Notas importantes ==
* Solo aparecen los tipos con al menos una **tarifa activa** (los que
  digan "Sin tarifa" en tu panel no se muestran — se venderian gratis).
* El shortcode de tarjetas es de LECTURA: no crea reservas ni cobra. El
  wizard incrustado (`[kuira_reservas]`) SI reserva y cobra: es tu motor
  de reservas hablando directo con tu sistema.
* Los mensajes de error solo los ven los administradores del sitio; los
  visitantes nunca ven avisos tecnicos.
* Para traer tu catalogo actual desde tu sitio actual hacia
  KuiraWebReserve (el sentido contrario), usa el "Agente importador" en
  **Integracion** dentro de tu panel — no requiere este plugin.

== Changelog ==

= 1.2.0 =
* Pantalla de ajustes rediseñada: tarjetas, estado de conexion con
  semaforo (Conectado / Con error / Sin probar) y guia de shortcodes.
* Marcador de tarjeta sin foto con degradado (mientras subes fotos).

= 1.1.0 =
* Fotos de cada tipo en las tarjetas (se leen del catalogo vivo).
* Diseño responsivo real: columnas configuradas en escritorio, 2 en
  tablet, 1 en celular; estilos en hoja propia.
* Precio con etiqueta de duracion ("por noche", etc.).
* Boton "Probar conexion" y estado de la conexion en Ajustes.
* Mensajes de error diferenciados (dominio mal, token invalido, modulo
  faltante, sin tarifas) — solo visibles para administradores.
* El campo Dominio limpia solo lo que le peguen (https://, rutas).
* Guardar ajustes invalida el cache al instante.
* Respaldo de 12 horas: si la API falla, se sirven los ultimos datos.
* Nuevos shortcodes de widgets: [kuira_reservas], [kuira_experiencias],
  [kuira_grupos].

= 1.0.0 =
* Version inicial.
