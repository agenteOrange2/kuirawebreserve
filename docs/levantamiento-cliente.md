# Levantamiento de información

**Lo que necesitamos de tu hotel o motel para dejarlo operando**

Este formato se llena una sola vez. Con él configuramos tu sistema completo: habitaciones,
tarifas, cobros, políticas y el asistente que atiende a tus clientes por chat.

**Cómo llenarlo**

- Contesta lo que aplique a tu negocio. Si algo no aplica, escribe **No aplica** — es una respuesta válida y nos ahorra la ida y vuelta.
- Lo marcado como **(requerido)** es lo mínimo para que el sistema funcione. Lo demás se puede agregar después.
- Si tienes la información en otro lado (un tarifario, un menú, un reglamento impreso), mándalo tal cual: no hace falta transcribirlo.
- Las fotos y logotipos van por separado, en la mejor calidad que tengas.

---

## 1. Datos del negocio

| Dato | Respuesta |
|---|---|
| Nombre comercial **(requerido)** | |
| Razón social (para facturar) | |
| ¿Es hotel, motel, o los dos? **(requerido)** | |
| Dirección completa **(requerido)** | |
| Liga de Google Maps | |
| Zona horaria | |
| Teléfono principal **(requerido)** | |
| Teléfono de WhatsApp (si es otro) | |
| Correo principal **(requerido)** | |
| Sitio web | |
| Facebook | |
| Instagram | |
| TikTok | |
| Liga para dejar reseña (Google) | |

**Logotipo:** mándalo en PNG o SVG, fondo transparente si se puede.

**Colores de la marca** (si los tienes, en código hexadecimal, ej. `#03045e`):

| Uso | Color |
|---|---|
| Color principal | |
| Color secundario | |

---

## 2. Horarios y moneda

| Dato | Respuesta |
|---|---|
| Hora de entrada (check-in) **(requerido)** | |
| Hora de salida (check-out) **(requerido)** | |
| ¿Hay horarios distintos por tipo de habitación? ¿Cuáles? | |
| Moneda con la que cobras **(requerido)** | |
| ¿Manejas una segunda moneda (ej. dólares)? ¿A qué tipo de cambio? | |
| ¿A qué hora cierra el día para el corte? | |

---

## 3. Zonas del inmueble

Las zonas son cómo está dividido tu lugar: pisos, edificios, alas, secciones. Sirven para que
el plano se parezca a la realidad.

| Nombre de la zona | Tipo (piso / edificio / área) | Cuántas habitaciones tiene |
|---|---|---|
| | | |
| | | |
| | | |
| | | |

---

## 4. Tipos de habitación

Un "tipo" agrupa habitaciones iguales (ej. Sencilla, Jacuzzi, Suite). Copia el bloque por cada tipo.

### Tipo 1

| Dato | Respuesta |
|---|---|
| Nombre del tipo **(requerido)** | |
| Descripción para el cliente (2 o 3 renglones, es lo que lee el huésped y lo que dice el bot) | |
| Personas incluidas en la tarifa **(requerido)** | |
| Máximo de personas permitidas **(requerido)** | |
| Costo por persona extra | |
| Amenidades (jacuzzi, cochera, TV, aire, cocina…) | |
| Liga de la página con fotos de este tipo | |
| ¿Tiene horario de entrada/salida distinto al general? | |

**Cargos opcionales de este tipo** (lo que se cobra aparte, si aplica):

| Concepto (ej. mascota, decoración, late checkout) | Importe |
|---|---|
| | |
| | |

> Repite este bloque por cada tipo de habitación. Si son muchos, mándalo en una hoja de cálculo con estas mismas columnas.

---

## 5. Habitaciones

El listado completo, una por renglón. Si son muchas, una hoja de cálculo con estas columnas es lo más rápido.

| Número | Nombre (opcional) | Zona | Tipo | Máx. personas | ¿Fumar? | Notas |
|---|---|---|---|---|---|---|
| | | | | | | |
| | | | | | | |
| | | | | | | |

**Si eres motel y llevas control de usos por habitación:**

| Dato | Respuesta |
|---|---|
| ¿Cuántos usos aguanta una habitación antes de mandarla a revisión? | |
| ¿Qué se hace cuando llega a ese límite? | |

---

## 6. Tarifas

Cada tarifa pertenece a **un** tipo de habitación. Puede ser por noche o por bloque de tiempo
(ratos, horas, semana, mes).

### Tarifa 1

| Dato | Respuesta |
|---|---|
| Nombre de la tarifa **(requerido)** (ej. "Noche", "12 horas", "Fin de semana") | |
| ¿A qué tipo de habitación aplica? **(requerido)** | |
| ¿Es por noche o por bloque de tiempo? **(requerido)** | |
| Si es por bloque: ¿cuánto dura? (ej. 12 horas, 3 días) | |
| Precio **(requerido)** | |
| ¿Pide anticipo? ¿De qué porcentaje? | |
| ¿Con cuánta anticipación mínima se puede reservar? | |
| ¿Cuánto tiempo tiene el cliente para pagar el saldo? | |
| ¿Hasta cuándo puede cancelar sin costo? | |
| Si cancela después de ese plazo, ¿cuánto se le retiene? | |

> Repite por cada tarifa.

**Temporadas y promociones** (si manejas precios distintos por fecha o por día de la semana):

| Nombre | Fechas o días que aplica | Qué cambia (precio fijo, % más, % menos) |
|---|---|---|
| | | |
| | | |

---

## 7. Cobros

### 7.1 Cómo cobras hoy

| Método | ¿Lo aceptas? | Notas |
|---|---|---|
| Efectivo en recepción | | |
| Tarjeta en recepción (terminal física) | | |
| Transferencia bancaria | | |
| Pago en línea (link de pago) | | |

### 7.2 Datos para transferencia

Si aceptas transferencia, necesitamos las cuentas tal cual se las vas a pasar al cliente:

| Banco | Titular de la cuenta | CLABE o número de cuenta | Notas |
|---|---|---|---|
| | | | |
| | | | |

| Dato | Respuesta |
|---|---|
| ¿A qué WhatsApp deben mandar el comprobante? | |
| ¿Cuánto tiempo tiene el cliente para transferir antes de que se libere la habitación? | |

### 7.3 Pasarela de pago en línea

Con esto el cliente paga desde el chat o desde tu página, y el sistema confirma solo.

| Dato | Respuesta |
|---|---|
| ¿Cuál usas o quieres usar? (Stripe, Mercado Pago o PayPal) | |
| ¿Ya tienes cuenta abierta con ellos? | |
| ¿Quién administra esa cuenta? (nombre y correo) | |

> **Las llaves NO las escribas aquí.** Cuando lleguemos a este punto te pasamos una liga
> privada para capturarlas, o las cargas tú desde tu propio panel. Son datos que no deben
> viajar por correo ni por WhatsApp.

### 7.4 Anticipos y saldos

| Dato | Respuesta |
|---|---|
| ¿Pides anticipo para apartar? **(requerido)** | |
| Si sí: ¿cuánto? (porcentaje o monto fijo) | |
| ¿Cuánto tiempo se le guarda la habitación a quien va a pagar en efectivo al llegar? | |
| ¿El saldo se cobra al llegar o antes? | |
| ¿Qué pasa si no paga el saldo a tiempo? | |
| ¿Pides depósito en garantía? ¿De cuánto? ¿Cuándo se devuelve? | |

---

## 8. Políticas de las habitaciones

Escríbelas como se las dirías a un cliente. Estas son las que el sistema muestra y las que
el asistente repite tal cual.

| Tema | Tu política |
|---|---|
| ¿Se puede fumar? ¿Dónde? | |
| ¿Aceptan mascotas? ¿Con qué condiciones o costo? | |
| ¿Cuántas personas pueden entrar por habitación? | |
| ¿Se permiten visitas de gente no registrada? | |
| ¿Qué pasa si hay daños o falta algo en la habitación? | |
| ¿Se puede extender la estancia? ¿Cómo se cobra? | |
| ¿Qué identificación piden al registrarse? | |
| ¿Aceptan menores de edad? | |
| ¿Qué pasa con objetos olvidados? | |
| ¿Se responsabilizan por objetos de valor? | |

---

## 9. Reglas del lugar

Lo que no se puede hacer y lo que sí. Aquí va todo lo que hoy le dices al cliente de palabra
o tienes pegado en la pared.

| Tema | Tu regla |
|---|---|
| ¿Se puede entrar con comida o bebida de fuera? | |
| ¿Se puede pedir comida a domicilio? ¿Entra el repartidor? | |
| ¿Se puede salir y volver a entrar durante la estancia? | |
| ¿Hay cochera? ¿Es privada? ¿Cuántos autos por habitación? | |
| ¿Manejan salones o áreas para eventos y fiestas? | |
| ¿Hay reglas de ruido o de horario? | |
| ¿Qué tan discreto es el acceso? (importante para motel) | |
| Otras reglas que apliquen | |

---

## 10. Términos y condiciones

| Dato | Respuesta |
|---|---|
| Política de cancelación tal como se la dices al cliente **(requerido)** | |
| ¿Qué pasa si el cliente no llega (no-show)? | |
| ¿Se puede cambiar la fecha de una reserva? ¿Con cuánta anticipación? | |
| ¿Devuelven dinero? ¿En qué casos? | |
| Aviso de privacidad: ¿tienes uno? Mándanos la liga o el texto | |
| ¿Facturan? ¿Qué datos piden y hasta cuándo se puede solicitar? | |
| Texto legal que quieras que aparezca al reservar | |

---

## 11. El asistente que atiende por chat

Este es el bot que contesta a tus clientes. Entre mejor esté esta sección, mejor atiende.

### 11.1 Cómo debe hablar

| Dato | Respuesta |
|---|---|
| ¿Cómo quieres que se presente? (ej. "el asistente de Motel La Cúpula") | |
| Tono: ¿formal, cercano, breve? | |
| ¿Hay palabras que NO debe usar? | |
| ¿Debe tutear o hablar de usted? | |

### 11.2 Qué SÍ puede hacer

Marca lo que quieres que haga solo, sin que intervenga una persona:

| Puede… | ¿Sí o no? |
|---|---|
| Dar precios y disponibilidad | |
| Apartar una habitación (crear la reserva) | |
| Mandar el link de pago | |
| Compartir fotos de las habitaciones | |
| Explicar cómo llegar | |
| Explicar políticas y reglas | |
| Consultar el estado de una reserva | |

### 11.3 Qué NO debe hacer nunca

| Dato | Respuesta |
|---|---|
| ¿Hay descuentos o promociones que NO debe ofrecer? | |
| ¿Hay temas que no debe tocar? | |
| ¿Hay algo que la gente pregunta seguido y prefieres que conteste una persona? | |
| ¿Puede confirmar que un pago ya se recibió? *(nuestra recomendación es que no: eso lo confirma el sistema o tu personal)* | |

### 11.4 Cuándo pasar con una persona

| Situación | ¿Pasar con una persona? |
|---|---|
| Una queja o reclamo | |
| Grupos grandes | |
| Pedir factura | |
| Cambios a una reserva ya pagada | |
| Cuando el cliente lo pide | |
| Otra situación: | |

| Dato | Respuesta |
|---|---|
| ¿A quién le llega el aviso cuando el bot pasa la conversación? | |
| ¿En qué horario hay alguien atendiendo el chat? | |
| ¿Qué debe decir el bot fuera de ese horario? | |

### 11.5 Preguntas frecuentes

Las que te hacen todos los días. Entre más pongas, menos veces te van a interrumpir.
Escríbelas como te las preguntan y contesta como contestarías tú.

| Pregunta | Respuesta |
|---|---|
| | |
| | |
| | |
| | |
| | |
| | |

### 11.6 A dónde mandar a la gente

| Dato | Respuesta |
|---|---|
| Página donde quieres que reserven (si ya tienes una) | |
| Página con las fotos de las habitaciones | |
| Página de contacto o ubicación | |
| ¿Quieres que el bot mande a tu página o que aparte él mismo en el chat? | |

---

## 12. Canales de mensajería

Por dónde te escriben tus clientes. Cada uno se conecta distinto; aquí solo dinos cuáles usas.

| Canal | ¿Lo usas? | Cuenta / número | ¿Quién la administra? |
|---|---|---|---|
| WhatsApp | | | |
| Messenger (Facebook) | | | |
| Instagram | | | |
| Telegram | | | |
| TikTok | | | |
| Chat en tu página web | | | |

> Para conectar Facebook e Instagram necesitamos que la persona que administra la página nos
> dé acceso un momento. Te mandamos la guía cuando lleguemos a ese paso.

---

## 13. Tu equipo

Quién va a entrar al sistema y con qué permiso.

| Nombre | Correo (será su usuario) | Teléfono | Puesto |
|---|---|---|---|
| | | | |
| | | | |
| | | | |

**Los puestos disponibles son:**

- **Propietario** — ve todo, incluidos reportes y facturación.
- **Gerente** — opera todo menos la configuración del negocio.
- **Recepción** — reservas, llegadas, salidas y cobros.
- **Limpieza** — el estado de las habitaciones.
- **Cocina** — los pedidos, si usas menú digital.

**Personal de limpieza (camaristas):** no entran al sistema, pero registramos su trabajo.
Danos sus nombres:

| Nombre | Teléfono (opcional) |
|---|---|
| | |
| | |

**Quién repara** (personal de mantenimiento y proveedores externos):

| Nombre o taller | Especialidad | Teléfono | ¿Es de casa o externo? |
|---|---|---|---|
| | | | |
| | | | |

---

## 14. Mantenimiento

| Dato | Respuesta |
|---|---|
| Cuando hay una falla urgente (fuga, sin luz, sin agua), ¿en cuántas horas debe quedar resuelta? | |
| Una falla normal (algo que molesta pero se puede ocupar el cuarto), ¿en cuántas horas? | |
| Una falla menor (un foco, un detalle), ¿en cuántas horas? | |
| ¿Quién debe enterarse cuando se reporta una falla? | |

**Catálogo de daños** — lo que cobras cuando el huésped rompe algo:

| Concepto | Importe |
|---|---|
| | |
| | |
| | |

---

## 15. Punto de venta y menú digital

Solo si vendes productos (tienda, cocina, servicio a la habitación).

| Dato | Respuesta |
|---|---|
| ¿Vendes productos aparte del hospedaje? | |
| ¿Tienes tu lista de productos con precios? (mándala como la tengas) | |
| ¿Quieres que el huésped pueda pedir desde su celular con un QR? | |
| ¿En qué horario se toman pedidos? | |
| ¿Cuánto tardan en promedio en entregar? | |
| ¿Se cobra al momento o se carga a la habitación? | |

---

## 16. Si eres motel

| Dato | Respuesta |
|---|---|
| ¿Registran la placa del vehículo? | |
| ¿Se cobra al entrar o al salir? | |
| ¿Revisan la habitación antes de dejar salir al cliente? | |
| ¿Manejan lista de vetados? | |
| ¿Hay tarifas por rato además de por noche? ¿Cuáles? | |

---

## 17. Encuestas de satisfacción

| Dato | Respuesta |
|---|---|
| ¿Quieres preguntarle al huésped cómo le fue después de su estancia? | |
| ¿Qué aspectos te interesa calificar? (limpieza, atención, instalaciones…) | |
| Si alguien califica bajo, ¿a quién se le avisa? | |
| ¿Quieres invitar a dejar reseña en Google a quien califique bien? | |

---

## 18. Lo que nos falta saber

Espacio libre. Cualquier cosa de tu operación que sea particular, que te preocupe, o que
hayas visto que otros sistemas no resuelven:

<br><br><br><br><br><br>

---

## Antes de mandarlo, revisa que traiga

- [ ] Los datos del negocio y el logotipo
- [ ] La lista completa de habitaciones con su tipo y su zona
- [ ] Al menos una tarifa por cada tipo de habitación
- [ ] Cómo cobras y si pides anticipo
- [ ] Las políticas, las reglas y la política de cancelación
- [ ] Las preguntas frecuentes que te hacen todos los días
- [ ] Los correos de quienes van a entrar al sistema
- [ ] Las fotos de las habitaciones (por separado, en buena calidad)

---

*Cualquier duda al llenarlo, escríbenos: es más rápido preguntar que dejar un campo en blanco.*
