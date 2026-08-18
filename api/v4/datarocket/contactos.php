<?php
// api/v4/datarocket/contactos.php
// ALIAS DE COMPATIBILIDAD — no tiene logica propia.
//
// El recurso se renombro a `prospectos` el 2026-08-17 junto con el resto del
// submodulo (migracion 20260817_2700). Esta ruta queda viva porque es publica:
// la consumen integraciones externas por Bearer y no hay forma de enumerarlas
// desde el repo, asi que romperla seria un cambio irreversible sobre clientes
// que no controlamos.
//
//   /v4/datarocket/contactos   (esta ruta, deprecada)  -> mismo comportamiento
//   /v4/datarocket/prospectos  (ruta canonica)
//
// El contrato JSON SI cambio: las claves `contacto_*` que devolvia el recurso
// ahora son `prospecto_*`. El alias preserva la RUTA, no el formato de la
// respuesta — un consumidor que lea esas claves tiene que actualizarse igual.
//
// PARA RETIRARLA: cuando confirmes que ningun consumidor pega mas aca, borra
// este archivo. No hay nada mas que limpiar.

require __DIR__ . '/prospectos.php';
