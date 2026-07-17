=== KuiraWebReserve Habitaciones ===
Contributors: kuirawebreserve
Tags: hotel, habitaciones, precios, reservas
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
License: GPL-2.0-or-later

Muestra tus tipos de habitacion con precio y amenidades EN VIVO desde
KuiraWebReserve.

== Description ==
Agrega a tu sitio WordPress una cuadricula con tus tipos de habitacion:
nombre, descripcion, capacidad, amenidades y precio "desde". El precio
SIEMPRE se consulta a tu sistema (con cache de 5 minutos) — nunca se
copia ni se guarda en WordPress. Cambias la tarifa en tu panel de
KuiraWebReserve y tu sitio se actualiza solo.

* Shortcode `[kuirawebreserve_rooms]` (opcional: `columns="2"`)
* Consulta el catalogo desde el SERVIDOR de WordPress: el token nunca
  viaja al navegador del visitante
* Boton "Reservar" configurable (WhatsApp, telefono, o tu pagina de
  reservas cuando este lista)
* Sin dependencias, sin jQuery, sin cuentas extra

== Installation ==
1. En tu panel de KuiraWebReserve, ve a **Integracion** y pulsa
   "Conectar sitio". Copia el token que se muestra (solo se ve una vez).
2. Sube la carpeta `kuirawebreserve-rooms` a `/wp-content/plugins/`
   (o instala el zip) y actívala.
3. Ve a **Ajustes → KuiraWebReserve Habitaciones**: pega el dominio de tu
   hotel (ej. `mihotel.kuirawebreserve.com`, sin "https://") y el token.
4. Pega el shortcode `[kuirawebreserve_rooms]` en la pagina de tu sitio
   donde quieras mostrar las habitaciones.

== Notas importantes ==
* Solo aparecen los tipos con al menos una **tarifa activa** (los que
  digan "Sin tarifa" en tu panel no se muestran — se venderian gratis).
* Este plugin es de LECTURA: no crea reservas ni cobra. El boton
  "Reservar" solo enlaza a donde tu configures.
* Para traer tu catalogo actual desde tu sitio actual hacia
  KuiraWebReserve (el sentido contrario), usa el "Agente importador" en
  **Integracion** dentro de tu panel — no requiere este plugin.
