-- Seed del set de permisos "cloud" (los que el nuevo panel usa para autorizar).
--
-- Convive con el set legacy: legacy => slug NULL (blanqueado por la migracion
-- 20260711_1200_limpiar_slug_y_descripcion_legacy.sql), cloud => slug con valor.
-- El nombre de cada permiso replica la ubicacion en el menu lateral izquierdo:
--
--     <Grupo> > <Modulo> > <Accion>                       (2 niveles + accion)
--     <Grupo> > <Plataforma> > <Modulo> > <Accion>        (3 niveles + accion, solo para Plataformas)
--     <Grupo> > Herramientas > <Herramienta> > <Accion>   (para el modulo Herramientas)
--
-- Acciones convencionales (sacadas de los handlers de la API):
--   consultar / agregar / editar / eliminar   -> ABMs estandar
--   ejecutar / aplicar / sincronizar / etc.   -> tools con verbos propios
--
-- Idempotencia: se cargan las filas en una TEMPORARY TABLE y despues se inserta
-- en `permisos` solo lo que todavia no existe (LEFT JOIN ... IS NULL). Correrla
-- dos veces no duplica filas ni cambia las existentes.

CREATE TEMPORARY TABLE tmp_permisos_cloud (
  slug   VARCHAR(100) NOT NULL,
  nombre VARCHAR(255) NOT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

INSERT INTO tmp_permisos_cloud (slug, nombre) VALUES
-- ============================================================================
-- Inicio
-- ============================================================================
('inicio.dashboard.consultar', 'Inicio > Dashboard > Consultar'),

-- ============================================================================
-- Datacount
-- ============================================================================
('datacount.comprobantes.consultar',  'Datacount > Comprobantes > Consultar'),
('datacount.comprobantes.agregar',    'Datacount > Comprobantes > Agregar'),
('datacount.comprobantes.editar',     'Datacount > Comprobantes > Editar'),
('datacount.comprobantes.eliminar',   'Datacount > Comprobantes > Eliminar'),

('datacount.facturacion.consultar',   'Datacount > Facturacion > Consultar'),
('datacount.facturacion.ejecutar',    'Datacount > Facturacion > Ejecutar'),

('datacount.asientos.consultar',      'Datacount > Asientos > Consultar'),
('datacount.asientos.agregar',        'Datacount > Asientos > Agregar'),
('datacount.asientos.editar',         'Datacount > Asientos > Editar'),
('datacount.asientos.eliminar',       'Datacount > Asientos > Eliminar'),

('datacount.empleados.consultar',     'Datacount > Empleados > Consultar'),
('datacount.empleados.agregar',       'Datacount > Empleados > Agregar'),
('datacount.empleados.editar',        'Datacount > Empleados > Editar'),
('datacount.empleados.eliminar',      'Datacount > Empleados > Eliminar'),

('datacount.recurrentes.consultar',   'Datacount > Recurrentes > Consultar'),
('datacount.recurrentes.agregar',     'Datacount > Recurrentes > Agregar'),
('datacount.recurrentes.editar',      'Datacount > Recurrentes > Editar'),
('datacount.recurrentes.eliminar',    'Datacount > Recurrentes > Eliminar'),

('datacount.cuentas.consultar',       'Datacount > Plan de cuentas > Consultar'),
('datacount.cuentas.agregar',         'Datacount > Plan de cuentas > Agregar'),
('datacount.cuentas.editar',          'Datacount > Plan de cuentas > Editar'),
('datacount.cuentas.eliminar',        'Datacount > Plan de cuentas > Eliminar'),

('datacount.empresas.consultar',      'Datacount > Empresas > Consultar'),
('datacount.empresas.agregar',        'Datacount > Empresas > Agregar'),
('datacount.empresas.editar',         'Datacount > Empresas > Editar'),
('datacount.empresas.eliminar',       'Datacount > Empresas > Eliminar'),

-- ============================================================================
-- Datarocket
-- ============================================================================
('datarocket.contactos.consultar',    'Datarocket > Contactos > Consultar'),
('datarocket.contactos.agregar',      'Datarocket > Contactos > Agregar'),
('datarocket.contactos.editar',       'Datarocket > Contactos > Editar'),
('datarocket.contactos.eliminar',     'Datarocket > Contactos > Eliminar'),

('datarocket.mensajes.consultar',     'Datarocket > Mensajes > Consultar'),
('datarocket.mensajes.agregar',       'Datarocket > Mensajes > Agregar'),
('datarocket.mensajes.editar',        'Datarocket > Mensajes > Editar'),
('datarocket.mensajes.eliminar',      'Datarocket > Mensajes > Eliminar'),

-- ============================================================================
-- Datasale
-- ============================================================================
('datasale.prospectos.consultar',     'Datasale > Prospectos > Consultar'),
('datasale.prospectos.agregar',       'Datasale > Prospectos > Agregar'),
('datasale.prospectos.editar',        'Datasale > Prospectos > Editar'),
('datasale.prospectos.eliminar',      'Datasale > Prospectos > Eliminar'),

-- ============================================================================
-- Plataformas
-- ============================================================================
-- AWS
('plataformas.aws.cuentas.consultar',            'Plataformas > AWS > Cuentas > Consultar'),
('plataformas.aws.cuentas.agregar',              'Plataformas > AWS > Cuentas > Agregar'),
('plataformas.aws.cuentas.editar',               'Plataformas > AWS > Cuentas > Editar'),
('plataformas.aws.cuentas.eliminar',             'Plataformas > AWS > Cuentas > Eliminar'),

-- AWS SES
('plataformas.awsses.canales.consultar',         'Plataformas > AWS SES > Canales > Consultar'),
('plataformas.awsses.canales.agregar',           'Plataformas > AWS SES > Canales > Agregar'),
('plataformas.awsses.canales.editar',            'Plataformas > AWS SES > Canales > Editar'),
('plataformas.awsses.canales.eliminar',          'Plataformas > AWS SES > Canales > Eliminar'),
('plataformas.awsses.mensajes.consultar',        'Plataformas > AWS SES > Mensajes > Consultar'),
('plataformas.awsses.mensajes.agregar',          'Plataformas > AWS SES > Mensajes > Agregar'),
('plataformas.awsses.mensajes.editar',           'Plataformas > AWS SES > Mensajes > Editar'),
('plataformas.awsses.mensajes.eliminar',         'Plataformas > AWS SES > Mensajes > Eliminar'),

-- Evolution API
('plataformas.evolution.canales.consultar',      'Plataformas > Evolution API > Canales > Consultar'),
('plataformas.evolution.canales.agregar',        'Plataformas > Evolution API > Canales > Agregar'),
('plataformas.evolution.canales.editar',         'Plataformas > Evolution API > Canales > Editar'),
('plataformas.evolution.canales.eliminar',       'Plataformas > Evolution API > Canales > Eliminar'),
('plataformas.evolution.contactos.consultar',    'Plataformas > Evolution API > Contactos > Consultar'),
('plataformas.evolution.contactos.agregar',      'Plataformas > Evolution API > Contactos > Agregar'),
('plataformas.evolution.contactos.editar',       'Plataformas > Evolution API > Contactos > Editar'),
('plataformas.evolution.contactos.eliminar',     'Plataformas > Evolution API > Contactos > Eliminar'),
('plataformas.evolution.mensajes.consultar',     'Plataformas > Evolution API > Mensajes > Consultar'),
('plataformas.evolution.mensajes.agregar',       'Plataformas > Evolution API > Mensajes > Agregar'),
('plataformas.evolution.mensajes.editar',        'Plataformas > Evolution API > Mensajes > Editar'),
('plataformas.evolution.mensajes.eliminar',      'Plataformas > Evolution API > Mensajes > Eliminar'),

-- Mercadopago
('plataformas.mercadopago.cuentas.consultar',       'Plataformas > Mercadopago > Cuentas > Consultar'),
('plataformas.mercadopago.cuentas.agregar',         'Plataformas > Mercadopago > Cuentas > Agregar'),
('plataformas.mercadopago.cuentas.editar',          'Plataformas > Mercadopago > Cuentas > Editar'),
('plataformas.mercadopago.cuentas.eliminar',        'Plataformas > Mercadopago > Cuentas > Eliminar'),
('plataformas.mercadopago.pagos.consultar',         'Plataformas > Mercadopago > Pagos > Consultar'),
('plataformas.mercadopago.pagos.agregar',           'Plataformas > Mercadopago > Pagos > Agregar'),
('plataformas.mercadopago.pagos.editar',            'Plataformas > Mercadopago > Pagos > Editar'),
('plataformas.mercadopago.pagos.eliminar',          'Plataformas > Mercadopago > Pagos > Eliminar'),
('plataformas.mercadopago.registros.consultar',     'Plataformas > Mercadopago > Registros > Consultar'),
('plataformas.mercadopago.registros.agregar',       'Plataformas > Mercadopago > Registros > Agregar'),
('plataformas.mercadopago.registros.editar',        'Plataformas > Mercadopago > Registros > Editar'),
('plataformas.mercadopago.registros.eliminar',      'Plataformas > Mercadopago > Registros > Eliminar'),
('plataformas.mercadopago.suscripciones.consultar', 'Plataformas > Mercadopago > Suscripciones > Consultar'),
('plataformas.mercadopago.suscripciones.agregar',   'Plataformas > Mercadopago > Suscripciones > Agregar'),
('plataformas.mercadopago.suscripciones.editar',    'Plataformas > Mercadopago > Suscripciones > Editar'),
('plataformas.mercadopago.suscripciones.eliminar',  'Plataformas > Mercadopago > Suscripciones > Eliminar'),
('plataformas.mercadopago.debitos.consultar',       'Plataformas > Mercadopago > Debitos > Consultar'),
('plataformas.mercadopago.debitos.agregar',         'Plataformas > Mercadopago > Debitos > Agregar'),
('plataformas.mercadopago.debitos.editar',          'Plataformas > Mercadopago > Debitos > Editar'),
('plataformas.mercadopago.debitos.eliminar',        'Plataformas > Mercadopago > Debitos > Eliminar'),

-- Dolarhoy
('plataformas.dolarhoy.cotizaciones.consultar',  'Plataformas > Dolarhoy > Cotizaciones > Consultar'),
('plataformas.dolarhoy.cotizaciones.agregar',    'Plataformas > Dolarhoy > Cotizaciones > Agregar'),
('plataformas.dolarhoy.cotizaciones.editar',     'Plataformas > Dolarhoy > Cotizaciones > Editar'),
('plataformas.dolarhoy.cotizaciones.eliminar',   'Plataformas > Dolarhoy > Cotizaciones > Eliminar'),

-- Movistar (SIMs incluye sync propio contra Kite Platform)
('plataformas.movistar.sims.consultar',          'Plataformas > Movistar > SIMs > Consultar'),
('plataformas.movistar.sims.agregar',            'Plataformas > Movistar > SIMs > Agregar'),
('plataformas.movistar.sims.editar',             'Plataformas > Movistar > SIMs > Editar'),
('plataformas.movistar.sims.eliminar',           'Plataformas > Movistar > SIMs > Eliminar'),
('plataformas.movistar.sims.sincronizar',        'Plataformas > Movistar > SIMs > Sincronizar'),

-- Claro
('plataformas.claro.sims.consultar',             'Plataformas > Claro > SIMs > Consultar'),
('plataformas.claro.sims.agregar',               'Plataformas > Claro > SIMs > Agregar'),
('plataformas.claro.sims.editar',                'Plataformas > Claro > SIMs > Editar'),
('plataformas.claro.sims.eliminar',              'Plataformas > Claro > SIMs > Eliminar'),

-- OpenAI
('plataformas.openai.consumos.consultar',        'Plataformas > OpenAI > Consumos > Consultar'),

-- ============================================================================
-- Seguridad
-- ============================================================================
('seguridad.usuarios.consultar',      'Seguridad > Usuarios > Consultar'),
('seguridad.usuarios.agregar',        'Seguridad > Usuarios > Agregar'),
('seguridad.usuarios.editar',         'Seguridad > Usuarios > Editar'),
('seguridad.usuarios.eliminar',       'Seguridad > Usuarios > Eliminar'),

('seguridad.roles.consultar',         'Seguridad > Roles > Consultar'),
('seguridad.roles.agregar',           'Seguridad > Roles > Agregar'),
('seguridad.roles.editar',            'Seguridad > Roles > Editar'),
('seguridad.roles.eliminar',          'Seguridad > Roles > Eliminar'),

('seguridad.permisos.consultar',      'Seguridad > Permisos > Consultar'),
('seguridad.permisos.agregar',        'Seguridad > Permisos > Agregar'),
('seguridad.permisos.editar',         'Seguridad > Permisos > Editar'),
('seguridad.permisos.eliminar',       'Seguridad > Permisos > Eliminar'),

-- ============================================================================
-- Administracion > Herramientas
-- ============================================================================
-- Grilla de herramientas (ver la pagina en si)
('administracion.herramientas.consultar',                       'Administracion > Herramientas > Consultar'),

-- Editor de parametros
('administracion.herramientas.parametros.consultar',            'Administracion > Herramientas > Parametros > Consultar'),
('administracion.herramientas.parametros.agregar',              'Administracion > Herramientas > Parametros > Agregar'),
('administracion.herramientas.parametros.editar',               'Administracion > Herramientas > Parametros > Editar'),
('administracion.herramientas.parametros.eliminar',             'Administracion > Herramientas > Parametros > Eliminar'),

-- Editor de estados
('administracion.herramientas.estados.consultar',               'Administracion > Herramientas > Estados > Consultar'),
('administracion.herramientas.estados.agregar',                 'Administracion > Herramientas > Estados > Agregar'),
('administracion.herramientas.estados.editar',                  'Administracion > Herramientas > Estados > Editar'),
('administracion.herramientas.estados.eliminar',                'Administracion > Herramientas > Estados > Eliminar'),

-- Explorador DB
('administracion.herramientas.explorador_db.consultar',         'Administracion > Herramientas > Explorador DB > Consultar'),
('administracion.herramientas.explorador_db.editar',            'Administracion > Herramientas > Explorador DB > Editar'),

-- Explorador S3
('administracion.herramientas.explorador_s3.consultar',         'Administracion > Herramientas > Explorador S3 > Consultar'),
('administracion.herramientas.explorador_s3.subir',             'Administracion > Herramientas > Explorador S3 > Subir'),
('administracion.herramientas.explorador_s3.crear_carpeta',     'Administracion > Herramientas > Explorador S3 > Crear carpeta'),
('administracion.herramientas.explorador_s3.eliminar',          'Administracion > Herramientas > Explorador S3 > Eliminar'),

-- Migrador DB
('administracion.herramientas.migrador_db.consultar',           'Administracion > Herramientas > Migrador DB > Consultar'),
('administracion.herramientas.migrador_db.aplicar',             'Administracion > Herramientas > Migrador DB > Aplicar'),

-- Sincronizador de tablas (solo entorno bajo)
('administracion.herramientas.sincronizador.ejecutar',          'Administracion > Herramientas > Sincronizador > Ejecutar'),

-- Programador de tareas
('administracion.herramientas.tareas.consultar',                'Administracion > Herramientas > Tareas > Consultar'),
('administracion.herramientas.tareas.agregar',                  'Administracion > Herramientas > Tareas > Agregar'),
('administracion.herramientas.tareas.editar',                   'Administracion > Herramientas > Tareas > Editar'),
('administracion.herramientas.tareas.eliminar',                 'Administracion > Herramientas > Tareas > Eliminar'),
('administracion.herramientas.tareas.ejecutar',                 'Administracion > Herramientas > Tareas > Ejecutar'),

-- Visor de sucesos
('administracion.herramientas.sucesos.consultar',               'Administracion > Herramientas > Sucesos > Consultar');

-- Insertar en `permisos` solo lo que todavia no existe (matching por slug).
-- El LEFT JOIN + IS NULL es la variante idempotente estandar para tablas sin
-- UNIQUE(slug) (convencion del grupo, ver comentarios de la migracion
-- 20260702_1500_backfill_slug_roles.sql).
INSERT INTO `permisos` (`slug`, `nombre`)
SELECT t.slug, t.nombre
FROM tmp_permisos_cloud t
LEFT JOIN `permisos` p ON p.slug = t.slug
WHERE p.id IS NULL;

DROP TEMPORARY TABLE tmp_permisos_cloud;
