# Font Awesome 6.5.1 Pro (autohospedado)

Copia local del paquete **Font Awesome Pro 6.5.1 — Web**. El panel ya no
carga FontAwesome desde `cdnjs.cloudflare.com`: `cloud/index.php` enlaza
directo estas hojas.

## Contenido

```
css/all.min.css            Classic (solid/regular/light/thin) + Duotone + Brands + shims v4/v5
css/sharp-solid.min.css    @font-face de la familia Sharp — peso 900
css/sharp-regular.min.css  @font-face de la familia Sharp — peso 400
css/sharp-light.min.css    @font-face de la familia Sharp — peso 300
css/sharp-thin.min.css     @font-face de la familia Sharp — peso 100
webfonts/*.woff2           Las 11 fuentes
icons.json                 Catálogo que consume Herramientas > Explorador FA6
LICENSE.txt                Licencia comercial de Fonticons, Inc.
```

Las cuatro hojas `sharp-*` son necesarias porque `all.min.css` **mapea** las
clases `.fa-sharp` / `.fasl` / `.fasr` / `.fass` / `.fast` a la familia
`"Font Awesome 6 Sharp"` pero **no declara sus `@font-face`**. Sin ellas,
`fa-sharp fa-solid fa-house` renderiza un cuadrado vacío.

## Diferencias contra el paquete original

- Sólo se copiaron los formatos **`.woff2`** (no los `.ttf`). Las referencias
  a `.ttf` se quitaron de los `@font-face` para no dejar URLs colgadas: todo
  navegador con soporte de `@font-face` variable soporta woff2 y el fallback
  truetype nunca se pedía.
- No se copiaron `less/`, `scss/`, `sprites/`, `svgs/`, `js/` ni el resto de
  los `.css` sueltos (`duotone.css`, `v4-shims.css`, …): el panel usa
  únicamente el modo webfont+CSS.

## Regenerar `icons.json`

`icons.json` es el catálogo compacto (~630 KB) que el Explorador FA6 baja una
vez por sesión. Se arma a partir de los metadatos del paquete oficial —
`metadata/icons.json`, `metadata/icon-families.json` y
`metadata/categories.yml` — con el script del repo:

```bash
python scripts/generar_fa6_icons.py "/ruta/al/fontawesome-6.5.1-web-pro"
```

Esquema de cada entrada (claves cortas para achicar el archivo):

| Clave | Significado                                                      |
|-------|------------------------------------------------------------------|
| `n`   | nombre del ícono (`house`) — la clase es `fa-<n>`                 |
| `l`   | etiqueta legible (`House`); ausente si es igual al nombre         |
| `u`   | codepoint (`f015`)                                               |
| `c`   | estilos Classic disponibles: `s`olid `r`egular `l`ight `t`hin `b`rands |
| `p`   | estilos Sharp disponibles: `s` `r` `l` `t`                        |
| `d`   | `1` si existe en Duotone                                         |
| `f`   | `1` si el ícono es Free (sin la marca, es sólo Pro)              |
| `t`   | sinónimos de búsqueda                                            |
| `a`   | alias (nombres viejos que siguen funcionando)                    |

## Al actualizar de versión

1. Reemplazar `css/` y `webfonts/` con los del paquete nuevo (mismo recorte:
   `all.min.css` + las cuatro `sharp-*`, sólo `.woff2`, sin refs a `.ttf`).
2. Regenerar `icons.json` con el script.
3. Actualizar el número de versión en `scripts/generar_fa6_icons.py`
   (constante `VERSION`) y en `cloud/STACK.md`.

El cache-busting de `index.php` usa el `filemtime` de `css/all.min.css`, así
que el navegador toma la versión nueva sin tocar nada más.
