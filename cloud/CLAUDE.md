# cloud

Aplicación **cloud**: panel de administración de Databox (correo masivo, WhatsApp masivo, etc.). DocumentRoot servido por Apache en `/var/www/html` dentro del contenedor `databox`.

El archivo [DESIGN.md](DESIGN.md) es el referente del sistema de diseño visual de esta aplicación. Consultarlo antes de proponer cambios de UI, agregar componentes o tocar `assets/css/style.css`.

El archivo [STACK.md](STACK.md) describe el stack técnico, estructura de carpetas, flujo de deploy y convenciones de código de esta aplicación. Consultarlo antes de proponer cambios en tecnologías, dependencias o estructura.

El esquema de la base de datos vive en [../db/schema.sql](../db/schema.sql) (declarado como fuente de verdad en el `CLAUDE.md` raíz). Consultarlo antes de escribir queries o tocar modelos.
