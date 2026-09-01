# -*- coding: utf-8 -*-
"""
Genera cloud/assets/fontawesome/icons.json, el catalogo compacto que consume
la herramienta "Explorador FA6" del panel.

Uso:
    python scripts/generar_fa6_icons.py "/ruta/al/fontawesome-6.5.1-web-pro"

La ruta apunta a la carpeta descomprimida del paquete Web de Font Awesome Pro
(la que contiene css/, webfonts/ y metadata/). Fuentes usadas:

    metadata/icons.json         label, unicode, terminos de busqueda, aliases
    metadata/icon-families.json que familias/estilos existen para cada icono
    metadata/categories.yml     categorias

Ver cloud/assets/fontawesome/README.md por el esquema de salida.
"""
import io
import json
import os
import re
import sys

VERSION = "6.5.1"
LICENCIA = "pro"

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DESTINO = os.path.join(RAIZ, "cloud", "assets", "fontawesome", "icons.json")


def leer_familias(path):
    """Familias y estilos disponibles por icono.

    icon-families.json pesa ~50 MB por los SVG embebidos, asi que se escanea
    por lineas en vez de parsearlo entero. El archivo viene pretty-printed con
    indentacion de 2 espacios y una jerarquia fija:

        indent 2 -> nombre del icono
        indent 4 -> claves del icono ("svgs", "aliases", ...)
        indent 6 -> familia dentro de "svgs" (classic / duotone / sharp)
        indent 8 -> estilo dentro de la familia
    """
    fam = {}
    icono = None
    en_svgs = False
    familia = None
    rx = re.compile(r'^(\s*)"([^"]+)": [\{\[]\s*$')
    with io.open(path, encoding="utf-8") as f:
        for linea in f:
            m = rx.match(linea)
            if not m:
                continue
            ind, key = len(m.group(1)), m.group(2)
            if ind == 2:
                icono = key
                fam[icono] = {}
                en_svgs = False
                familia = None
            elif ind == 4 and icono:
                en_svgs = (key == "svgs")
                familia = None
            elif ind == 6 and en_svgs:
                familia = key
                fam[icono][familia] = []
            elif ind == 8 and en_svgs and familia:
                fam[icono][familia].append(key)
    return fam


def leer_categorias(path):
    """categories.yml tiene una forma fija y plana; no hace falta un parser YAML
    (los contenedores no traen PyYAML)."""
    cats = []
    actual = None
    with io.open(path, encoding="utf-8") as f:
        for linea in f:
            linea = linea.rstrip("\n")
            if not linea.strip():
                continue
            m = re.match(r"^([A-Za-z0-9_-]+):$", linea)
            if m:
                actual = {"k": m.group(1), "l": m.group(1), "i": []}
                cats.append(actual)
                continue
            if actual is None:
                continue
            m = re.match(r"^  label: (.+)$", linea)
            if m:
                actual["l"] = m.group(1).strip().strip("'\"")
                continue
            m = re.match(r"^    - (.+)$", linea)
            if m:
                actual["i"].append(m.group(1).strip().strip("'\""))
    return cats


def main(argv):
    if len(argv) != 2:
        print(__doc__.strip())
        return 2

    src = argv[1]
    meta_dir = os.path.join(src, "metadata")
    if not os.path.isdir(meta_dir):
        print("No existe %s — la ruta debe apuntar a la carpeta del paquete Web." % meta_dir)
        return 1

    familias = leer_familias(os.path.join(meta_dir, "icon-families.json"))
    categorias = leer_categorias(os.path.join(meta_dir, "categories.yml"))
    with io.open(os.path.join(meta_dir, "icons.json"), encoding="utf-8") as f:
        meta = json.load(f)

    orden = ["solid", "regular", "light", "thin"]
    codigo = {"solid": "s", "regular": "r", "light": "l", "thin": "t", "brands": "b"}

    iconos = []
    for nombre in sorted(meta.keys()):
        v = meta[nombre]
        fam = familias.get(nombre, {})
        classic = fam.get("classic", [])
        sharp = fam.get("sharp", [])

        c = "".join(codigo[e] for e in orden if e in classic)
        if "brands" in classic:
            c += "b"
        p = "".join(codigo[e] for e in orden if e in sharp)

        it = {"n": nombre, "u": v.get("unicode", "")}
        etiqueta = v.get("label") or nombre
        if etiqueta != nombre:
            it["l"] = etiqueta
        if c:
            it["c"] = c
        if p:
            it["p"] = p
        if fam.get("duotone"):
            it["d"] = 1
        if v.get("free"):
            it["f"] = 1

        terminos = [str(t) for t in ((v.get("search") or {}).get("terms") or [])]
        if terminos:
            it["t"] = terminos
        alias = ((v.get("aliases") or {}).get("names") or [])
        if alias:
            it["a"] = alias

        iconos.append(it)

    salida = {
        "version": VERSION,
        "license": LICENCIA,
        "categories": categorias,
        "icons": iconos,
    }
    with io.open(DESTINO, "w", encoding="utf-8", newline="") as f:
        json.dump(salida, f, ensure_ascii=False, separators=(",", ":"))

    huerfanos = [i["n"] for i in iconos if not i.get("c") and not i.get("p") and not i.get("d")]
    print("destino:     %s" % DESTINO)
    print("iconos:      %d" % len(iconos))
    print("categorias:  %d" % len(categorias))
    print("con sharp:   %d" % sum(1 for i in iconos if i.get("p")))
    print("con duotone: %d" % sum(1 for i in iconos if i.get("d")))
    print("brands:      %d" % sum(1 for i in iconos if "b" in i.get("c", "")))
    print("sin estilo:  %d %s" % (len(huerfanos), huerfanos[:10]))
    print("bytes:       %d" % os.path.getsize(DESTINO))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
