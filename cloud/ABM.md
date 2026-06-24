# Convenciones de módulos ABM

Reglas para generar módulos ABM (Alta, Baja, Modificación). Todo módulo nuevo debe respetarlas salvo indicación contraria.

## Tarjeta de ayuda del módulo

Todo módulo ABM abre con una **tarjeta de ayuda** que explica, en una o
dos oraciones, qué representa el recurso del módulo. Es la primera vez
que un usuario nuevo entiende qué está mirando — no se omite en ningún
módulo.

### Reglas

- **Posición:** primer elemento dentro de `<div class="section">`, **arriba
  de todo**: antes de la `stats-bar`, la `toolbar` y la tabla.
- **Ancho:** ocupa el **100% del ancho** del `.content`, en una sola fila,
  sin grilla ni columnas.
- **Ícono:** el **mismo emoji** que usa el ítem del sidebar para ese
  módulo (también el mismo que se use como avatar/representación del
  recurso en otros lugares del admin). Si el sidebar dice 🛒 Carritos, la
  tarjeta arranca con 🛒.
- **Alineación vertical:** ícono y texto van **centrados verticalmente**
  dentro del alto disponible de la tarjeta (`align-items:center`). Si el
  texto ocupa una sola línea, ambos quedan en el medio; si ocupa dos o
  tres líneas, el ícono se mantiene centrado respecto al bloque de
  texto. Nunca alinear arriba (`flex-start`) ni abajo (`flex-end`).
- **Texto:** una o dos oraciones, máximo ~3 líneas. **No** se incluye
  título ni encabezado: el contexto de "qué módulo estoy mirando" ya lo
  dan el sidebar y la topbar.
- **Forma del texto:** debe empezar con `Los/las <recurso> son/tienen…` y
  explicar **qué representa la entidad** en este sistema (qué es), no
  cómo se usa la pantalla. Si hace falta mencionar acciones, va al final
  en una sola oración corta.
- **Una sola tarjeta por módulo.** No se anida con otras ayudas
  contextuales ni se reemplaza por tooltips.

### Ejemplos de texto

- **Carritos** (🛒): "Los carritos son las compras en curso de cada
  cliente, estén abiertos, abandonados o ya convertidos en pedido."
- **Solicitantes** (📍): "Los solicitantes son clientes potenciales que
  entraron a la app, confirmaron una ubicación y quedaron fuera de toda
  zona de cobertura activa…"
- **Proveedores** (🏭): "Los proveedores son las empresas a las que les
  compramos mercadería para reponer stock."
- **Repartidores** (🛵): "Los repartidores son las personas que entregan
  los pedidos a los clientes."

### Plantilla HTML

Reemplazar el emoji y el texto por los del recurso. Las clases y los
estilos inline son los mismos para todos los módulos — no inventar
variantes.

```html
<div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
  <div style="font-size:1.6rem;line-height:1">🛒</div>
  <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
    Los carritos son las compras en curso de cada cliente, estén
    abiertos, abandonados o ya convertidos en pedido.
  </div>
</div>
```

**Ejemplo de referencia:** el módulo de Solicitantes implementa esta
tarjeta en `admin/index.php` (`<div class="module-help">` al inicio de
`#seccionSolicitantes`). Usar ese módulo como plantilla viva al portar
este patrón a otros recursos.

## Listado

Las columnas del listado deben respetar este orden:

1. **Primera columna: `Código`**
   - Corresponde al ID de la tabla.
   - El título de la columna es `Código` (no `ID`).

2. **Columnas importantes de la tabla**
   - Los campos relevantes de la entidad.

3. **Última columna: `Acciones`**
   - El título de la columna es `Acciones`.
   - El título y el contenido de la columna deben estar **centrados** (`text-align: center` en `<th>` y `<td>`; `.actions { justify-content: center }` en el contenedor flex).
   - Contiene **un único ícono**: el botón hamburguesa (`fa-bars`), que abre el [menú contextual](#acciones-del-registro-menú-contextual) del registro.
   - **No** se muestran íconos sueltos para Consultar (ojo), Editar (lápiz) ni Eliminar (tacho). Todas esas acciones viven dentro del menú contextual.
   - Si un módulo previo todavía conserva los íconos sueltos de ojo, lápiz o tacho, hay que **quitarlos** y dejar únicamente el hamburguesa: el menú contextual ya es un superset funcional de esas acciones.

### Límite de resultados
- Por defecto: **100**.
- Modificable por el usuario desde el campo `Límite` del buscador.

### Acciones del registro (menú contextual)

Todas las acciones por fila — tanto las genéricas (**Consultar / Editar / Eliminar**) como las **propias del recurso** (ej. *Marcar como oferta*, *Cambiar estado*, *Actualizar precio*, *Duplicar*, etc.) — viven en un **único menú contextual** por fila. La columna `Acciones` no expone ningún ícono suelto: el único punto de entrada visible es el botón hamburguesa.

El menú es accesible desde dos (opcionalmente tres) entradas equivalentes:

1. **Botón hamburguesa (`fa-bars`)** — único contenido de la columna `Acciones`, centrado.
2. **Clic derecho sobre cualquier parte de la fila** (`contextmenu`).
3. *(opcional)* Atajo de teclado, si el módulo lo justifica.

Todas las entradas abren **el mismo menú**, con las mismas opciones y el mismo orden. Nunca duplicar el menú en HTML — un único `<div class="ctx-menu">` por sección, posicionado dinámicamente.

**Contenido obligatorio del menú** (en este orden, de arriba hacia abajo):

1. **Consultar** (`fa-eye`) — abre el modal de Consulta.
2. **Acciones propias del recurso** — toggles, atajos o acciones específicas (si las hay).
3. *Separador* (`<div class="ctx-menu-sep"></div>`).
4. **Editar** (`fa-pen`).
5. **Eliminar** (`fa-trash`, con clase `ctx-menu-danger`, siempre al final).

**Reglas del menú contextual:**
- Cada opción es un `<button data-action="…">` con ícono FontAwesome a la izquierda y etiqueta a la derecha.
- Las opciones que dependen del estado del registro (toggles tipo *Marcar / Quitar*, *Mostrar / Ocultar*) deben actualizar su etiqueta dinámicamente al abrirse.
- Usar `<div class="ctx-menu-sep"></div>` para separar grupos de acciones (ej. acciones del recurso vs. acciones genéricas Editar/Eliminar).
- Las acciones destructivas (Eliminar) llevan la clase `ctx-menu-danger` y van **al final**.
- El menú se cierra al hacer clic afuera, hacer scroll, redimensionar la ventana o presionar `Escape`.
- El botón hamburguesa debe llamar a `stopPropagation()` para que el handler global que cierra el menú al clickear afuera no lo cierre en el mismo click que lo abre.

```html
<!-- Celda de Acciones en cada fila (centrada) -->
<td style="text-align:center">
  <div class="actions" style="justify-content:center">
    <button class="btn-icon-sm" title="Más acciones"
            onclick="event.stopPropagation(); abrirMenuContexto…(event, ID)">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</td>

<!-- Menú contextual único por sección (fuera del <table>) -->
<div id="…CtxMenu" class="ctx-menu" role="menu">
  <button type="button" data-action="consultar" role="menuitem">
    <i class="fa-solid fa-eye"></i><span>Consultar</span>
  </button>
  <!-- Acciones específicas del recurso (toggles, atajos) -->
  <button type="button" data-action="…" role="menuitem">
    <i class="fa-solid fa-…"></i><span data-label>…</span>
  </button>
  <div class="ctx-menu-sep"></div>
  <button type="button" data-action="editar" role="menuitem">
    <i class="fa-solid fa-pen"></i><span>Editar</span>
  </button>
  <button type="button" data-action="eliminar" class="ctx-menu-danger" role="menuitem">
    <i class="fa-solid fa-trash"></i><span>Eliminar</span>
  </button>
</div>
```

### Interacción con la fila

Además del botón hamburguesa y el menú contextual, la fila completa responde a estos gestos:

- **Clic izquierdo sobre la fila** → abre la **Consulta** del registro. El clic propaga sólo si no se hizo sobre el botón de la columna `Acciones` ni sobre un elemento interactivo dentro de la fila (link, checkbox, badge clickeable).
- **Clic derecho sobre la fila** → abre el menú contextual descripto arriba.

Esto vuelve la consulta accesible sin necesidad de apuntar al ícono pequeño y mantiene una experiencia consistente entre todos los listados.

## Buscador

El buscador se divide en dos partes: una **toolbar mínima** arriba del listado y un **modal de filtros** donde se concentra el resto.

### Toolbar (arriba del listado)

La toolbar tiene exactamente tres controles a la izquierda y, opcionalmente, los botones de acción primaria a la derecha:

1. **Input de búsqueda rápida** (único campo visible)
   - Tipo: texto.
   - Placeholder: `🔍 Buscar <campos>…` enumerando los campos por los que busca (ej. `🔍 Buscar nombre, SKU o EAN…`).
   - Debe incluir botón `×` para limpiar la búsqueda, oculto cuando está vacío.

2. **Botón `Filtros`** — solo ícono (`fa-filter`).
   - Sin texto. Sólo el ícono y, opcionalmente, un **badge** circular naranja en la esquina superior derecha con la cantidad de filtros activos.
   - Cuando hay al menos un filtro activo, el botón se marca con borde y color de marca (`.btn.btn-icon.active`).
   - Al hacer clic abre el **modal de filtros** descripto más abajo.

3. **Botón `Refrescar`** — solo ícono (`fa-rotate`).
   - Sin texto. Al hacer clic vuelve a ejecutar la consulta con los filtros actuales.

A la derecha de la toolbar se ubican las acciones primarias del listado (típicamente `+ Nuevo …`).

```html
<div class="toolbar">
  <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
    <div class="search-wrap">
      <input class="search-input" type="text" placeholder="🔍 Buscar …" oninput="…">
      <button class="search-clear" style="display:none">×</button>
    </div>
    <button class="btn btn-ghost btn-icon" title="Filtros" onclick="abrirModalFiltros()">
      <i class="fa-solid fa-filter"></i>
      <span class="btn-icon-badge" style="display:none">0</span>
    </button>
    <button class="btn btn-ghost btn-icon" title="Refrescar" onclick="cargar…()">
      <i class="fa-solid fa-rotate"></i>
    </button>
  </div>
  <div class="toolbar-right">
    <button class="btn btn-primary" onclick="abrirNuevo…()">+ Nuevo …</button>
  </div>
</div>
```

### Modal de filtros

El modal concentra **todos** los demás filtros y configuraciones de la consulta. Se abre con el botón `Filtros` de la toolbar y es **el mismo formato para todos los módulos del proyecto**, sin variaciones. Cualquier filtro disperso por el proyecto debe ajustarse a esta estructura.

#### Estructura obligatoria

1. **Header** — siempre igual:
   - Título a la izquierda: ícono `fa-filter` + texto `Filtros`.
   - Botón `✕` a la derecha (`.btn.btn-ghost`) — equivale a `Cerrar` del footer (revierte cambios y cierra).

2. **Body** — orden fijo de campos:

   1. **Primer campo: `Código`**
      - Tipo: numérico.
      - Etiqueta: `Código`.
      - Corresponde al ID de la entidad.

   2. **Campos comunes del recurso**
      - Los filtros propios de la entidad (selects, chips de estado, fechas, etc.).
      - Si hay un filtro de **categoría / agrupador** asociado al recurso, se muestra acá (puede ser sólo lectura si la selección se hace desde otro módulo).
      - Los filtros booleanos se renderizan como **chips** (`.filter-chip`), agrupados bajo una etiqueta común (ej. `Estado del producto`).

   3. **Última fila: `Límite` + `Ordenar por` + `Dirección`** (siempre presentes, en este orden)
      - **`Límite`**: numérico con control up/down. Valor por defecto `100`. Rango `min="1" max="1000"`.
      - **`Ordenar por`**: select con los campos por los que se puede ordenar (el primero suele ser `Código`).
      - **`Dirección`**: select con `Descendente` / `Ascendente`. Por defecto `Descendente`.
      - Esta fila usa `.form-row.form-row-3` para alinear los tres en una sola línea.
      - Es obligatoria en todos los módulos — si un recurso no tiene un campo de orden adicional, basta con dejar `Código` como única opción.

3. **Footer** — siempre tres botones, en este orden de izquierda a derecha:
   - **`Cerrar`** (`.btn-ghost`) — revierte los filtros al estado que tenían al abrir el modal y cierra.
   - **`Limpiar`** (`.btn-ghost`) — resetea código, chips, categoría, límite y orden a sus valores por defecto y vuelve a ejecutar la consulta (no cierra el modal).
   - **`Aplicar`** (`.btn-primary`, **único botón naranja**) — cierra el modal sin tocar el estado actual.

   La acción primaria de este modal es **`Aplicar`** y debe ser el único `.btn-primary` del footer.

#### Comportamiento (obligatorio)

- Los cambios en los controles del modal se aplican **en vivo** sobre la lista de fondo. `Aplicar` sólo cierra el modal.
- Al abrir el modal:
  - Los controles se sincronizan con el estado actual de los filtros.
  - Se toma un **snapshot** del estado de los filtros, que `Cerrar` usa para revertir.
- Después de cada cambio en los filtros, actualizar el badge del botón `Filtros` con la cantidad de filtros activos (un filtro cuenta como activo cuando difiere de su valor por defecto).
- El click sobre el backdrop equivale a `Cerrar` (revierte y cierra) — nunca a `Aplicar`.

#### Plantilla HTML genérica

Reemplazar `recurso` por el nombre del recurso (ej. `productos`, `pedidos`, `clientes`). Mantener literalmente el orden y las clases.

```html
<div class="modal-backdrop" id="filtrosRecursoBackdrop"
     onclick="if(event.target===this)cancelarFiltrosRecurso()">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <div class="modal-title"><i class="fa-solid fa-filter"></i> Filtros</div>
      <button class="btn btn-ghost" onclick="cancelarFiltrosRecurso()" title="Cerrar">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label>Código</label>
          <input type="number" min="1" placeholder="ID …" oninput="…">
        </div>
        <!-- Otros campos comunes del recurso -->
      </div>
      <div class="form-group">
        <label>Estado</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <!-- chips de filtros booleanos -->
        </div>
      </div>
      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Límite</label>
          <input type="number" min="1" max="1000" value="100" onchange="…">
        </div>
        <div class="form-group">
          <label>Ordenar por</label>
          <select onchange="…">
            <option value="id">Código</option>
            <!-- otros campos ordenables -->
          </select>
        </div>
        <div class="form-group">
          <label>Dirección</label>
          <select onchange="…">
            <option value="desc">Descendente</option>
            <option value="asc">Ascendente</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost"   onclick="cancelarFiltrosRecurso()">Cerrar</button>
      <button class="btn btn-ghost"   onclick="limpiarFiltrosRecurso()">Limpiar</button>
      <button class="btn btn-primary" onclick="cerrarModalFiltrosRecurso()">Aplicar</button>
    </div>
  </div>
</div>
```

#### Plantilla JS genérica

Cada módulo expone **cuatro funciones** con nombres simétricos (`Recurso` reemplazado por el nombre del módulo):

```js
let filtrosRecursoSnapshot = null;

function abrirModalFiltrosRecurso() {
  filtrosRecursoSnapshot = { /* copiar acá todas las variables de filtro del módulo */ };
  // Reflejar el estado actual en los controles del modal
  // …
  document.getElementById('filtrosRecursoBackdrop').classList.add('open');
}

function cerrarModalFiltrosRecurso() {
  document.getElementById('filtrosRecursoBackdrop').classList.remove('open');
}

function cancelarFiltrosRecurso() {
  if (filtrosRecursoSnapshot) {
    // Restaurar cada variable desde el snapshot
    // …
    cargarRecurso();
  }
  cerrarModalFiltrosRecurso();
}

function limpiarFiltrosRecurso() {
  // Resetear cada variable a su valor por defecto
  // Sincronizar UI (inputs, selects, chips)
  // …
  cargarRecurso();
}
```

**Ejemplo de referencia:** el módulo de productos implementa este patrón completo en `admin/index.php` (modal `filtrosProductosBackdrop`) y `admin/assets/js/admin.js` (funciones `abrirModalFiltros`, `cerrarModalFiltros`, `cancelarFiltrosProductos`, `limpiarFiltrosProductos`). Usar ese módulo como plantilla viva al portar este patrón a otros recursos.

### Botones solo-ícono de la toolbar

Los botones `Filtros` y `Refrescar` deben ser **únicamente ícono** (sin texto). Usan la clase `.btn.btn-ghost.btn-icon` y FontAwesome 6. El badge opcional usa `.btn-icon-badge`. Los estilos viven en `admin/assets/css/admin.css` y no deben duplicarse por módulo.

## Modales

### Consultar
- Al abrir el modal de **Consultar**, se deben mostrar **todos los campos** del registro seleccionado (no solo los que aparecen en el listado).
- Los campos se muestran en modo lectura.
- Cada campo se renderiza en una **tarjeta individual** (`div`) con:
  - **Esquinas redondeadas**.
  - **Sin bordes** (`border: none`). Las tarjetas se diferencian del fondo del modal únicamente por el color de fondo, no por un borde.
  - **Color de fondo exactamente un 10% más oscuro** que el color de fondo del modal. Implementación recomendada en CSS: `background: color-mix(in srgb, var(--surface) 90%, #000);`. Este valor es **obligatorio** y no debe variarse por módulo.
  - Etiqueta del campo y valor dentro de la misma tarjeta.
- **Ancho de las tarjetas**:
  - Cuando el valor del campo puede mostrarse con **pocos caracteres** (códigos, números, fechas, estados, booleanos, etc.), la tarjeta ocupa el **50% del ancho** de la fila, permitiendo dos tarjetas por fila.
  - Cuando el valor requiere más espacio (descripciones largas, observaciones, direcciones completas, etc.), la tarjeta ocupa el **100% del ancho** de la fila.

#### Acciones del footer

El footer del modal **Consultar** siempre tiene a la derecha **dos botones fijos**, en este orden:

1. **Cerrar** (`.btn-ghost`) — cierra el modal.
2. **Editar** (`.btn-primary`) — cierra el modal y abre el modal de Edición del mismo registro.

Cualquier acción adicional propia del recurso (*Copiar URL*, *Imprimir*, *Duplicar*, *Marcar como…*, etc.) **no se suma como botón suelto en el footer**. Todas se agrupan en un **único menú contextual** abierto desde un botón hamburguesa (`fa-bars`) alineado a la **derecha** del footer, **inmediatamente antes del botón `Cerrar`**. Aunque la acción extra sea una sola, va dentro de ese menú — no como botón adicional en el footer — para mantener consistencia visual entre todos los modales de Consultar del proyecto.

**Layout del footer:**
- `display: flex; justify-content: flex-end;` (el patrón estándar de `.modal-footer`).
- Todos los botones van a la derecha en este orden, de izquierda a derecha: **hamburguesa → `Cerrar` → `Editar`**.
- El botón hamburguesa usa `.btn.btn-ghost.btn-icon` con `<i class="fa-solid fa-bars"></i>`.
- Si el modal de Consultar **no tiene** ninguna acción extra, el botón hamburguesa se omite y quedan solo `Cerrar` y `Editar` a la derecha.

**Reglas del menú contextual:**
- Reutiliza la clase `.ctx-menu` (definida en `admin/assets/css/admin.css`) — mismo look & feel que el menú contextual de las filas del listado descripto más arriba.
- Se posiciona por encima del botón hamburguesa (o debajo si no entra arriba) usando `position: fixed`.
- Se cierra al hacer clic afuera, scroll, resize o `Escape`.
- Las acciones destructivas, si las hubiera, llevan la clase `ctx-menu-danger` y van al final, separadas con `<div class="ctx-menu-sep"></div>`.

```html
<div class="modal-footer">
  <button class="btn btn-ghost btn-icon" title="Más acciones" onclick="abrirMenuContexto…(event)">
    <i class="fa-solid fa-bars"></i>
  </button>
  <button class="btn btn-ghost" onclick="cerrar…()">Cerrar</button>
  <button class="btn btn-primary" id="btn…Editar">✏️ Editar</button>
</div>

<!-- Menú contextual del modal Consultar (fuera del .modal-backdrop) -->
<div id="…CtxMenu" class="ctx-menu" role="menu">
  <button type="button" data-action="copiar-url" role="menuitem">
    <i class="fa-solid fa-link"></i><span>Copiar URL</span>
  </button>
  <!-- otras acciones del recurso -->
</div>
```

### Alta / Edición
- El modal de **crear un nuevo registro** y el de **editar** deben incluir **todos los campos** de la entidad.
- Ambos modales comparten la misma estructura de campos; la única diferencia es si vienen precargados con los datos del registro (edición) o vacíos (alta).