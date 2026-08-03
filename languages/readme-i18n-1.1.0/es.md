<!-- a11yfy readme 1.1.0 új blokkjai — es (deepseek-lektorált fordítás, 2026-08-03) -->

### Description — nuevo párrafo

Tres modos de funcionamiento: **automático** (cada nuevo archivo que subas se corrige automáticamente), **manual** (tú eliges qué corregir) y **bajo demanda**: los visitantes que hagan clic en un PDF que aún no sea accesible pueden solicitar una versión accesible desde un cuadro de diálogo accesible y recibir un correo electrónico cuando esté lista, de modo que solo pagas por los documentos que la gente realmente necesita.

### External Services — bullet modificado

* **Cuando corriges un PDF** (manualmente, mediante una acción en lote, a través del modo automático que hayas activado o cuando un visitante solicita una versión accesible en el modo bajo demanda que hayas activado): el propio archivo PDF, su nombre y tu clave API se envían a `https://a11yfy.com/v1/jobs`. El procesamiento se realiza en la UE. La dirección de correo electrónico de quien lo solicita se almacena únicamente en tu sitio y nunca se envía a la API de a11yfy.

### FAQ — nueva pregunta + respuesta

= ¿Cómo funciona el modo "Bajo demanda"? =

Cuando un visitante hace clic en un enlace a un PDF que no ha superado la comprobación previa de accesibilidad, aparece un cuadro de diálogo accesible. El visitante puede abrir el documento tal cual o solicitar una versión accesible introduciendo su dirección de correo electrónico. El plugin corrige el documento una sola vez —sin importar cuántos visitantes lo pidan— y envía un correo a todas las personas que lo solicitaron en cuanto la versión accesible esté disponible. A partir de ese momento, todos los visitantes reciben el archivo accesible en el mismo enlace, sin cuadro de diálogo. La dirección de correo electrónico se utiliza exclusivamente para esta notificación, nunca se envía a la API de a11yfy y se elimina automáticamente después de 30 días. Los textos del cuadro de diálogo, el correo de notificación y el estilo de los botones pueden personalizarse en a11yfy → Ajustes.

### Changelog — 1.1.0

= 1.1.0 =
* Nuevo modo de funcionamiento "Bajo demanda": cuando un visitante hace clic en un PDF que aún no es accesible, un cuadro de diálogo accesible le ofrece abrir el documento tal cual o solicitar una versión accesible por correo electrónico. La corrección solo se ejecuta cuando hay demanda real.
* Los visitantes reciben una notificación por correo electrónico en cuanto la versión accesible está lista; todos los textos del cuadro de diálogo (incluida la nota de privacidad) y el correo de notificación son totalmente personalizables en Ajustes.
* El cuadro de diálogo hereda la tipografía de tu tema y, en los temas de bloques, los botones adoptan automáticamente el estilo de los botones del tema; también puedes elegir un color de acento.
* Si el saldo de créditos no cubre una solicitud de un visitante, la solicitud se pone en espera, se avisa por correo electrónico al administrador del sitio y la corrección comienza automáticamente cuando haya suficientes créditos disponibles.
* Las direcciones de correo electrónico de los solicitantes se almacenan solo hasta que se envía la notificación (retención de 30 días), con soporte para la exportación y el borrado de datos personales de WordPress.
