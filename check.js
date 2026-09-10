function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}
const DCCH_CLASE_META = {
  electronica: { label: 'Electrónica', badge: 'badge-info'  },
  papel:       { label: 'Papel',       badge: 'badge-muted' },
};
const DCCH_TIPO_META = {
  comun:    { label: 'Común',    badge: 'badge-info' },
  diferido: { label: 'Diferido', badge: 'badge-warn' },
};
function dcchClaseBadge(v) {
  const m = DCCH_CLASE_META[v] || { label: v || '—', badge: 'badge-muted' };
  return `<span class="badge ${m.badge}">${esc(m.label)}</span>`;
}
function dcchEtiquetaChequera(c) {
  if (!c) return '—';
  return [
    c.nombre || `Cuenta #${c.cuenta_id}`,
    DCCH_CLASE_META[c.clase]?.label || c.clase,
    DCCH_TIPO_META[c.tipo]?.label   || c.tipo,
  ].filter(Boolean).join(' · ');
}
const DCQ_CLASE_LABEL = { electronica: 'Electrónico', papel: 'Papel' };
function dcqClasePildora(v) {
  const m = DCCH_CLASE_META[v];
  if (!m) return '';
  const label = DCQ_CLASE_LABEL[v] || m.label;
  return `<span class="badge ${m.badge}" style="font-size:.7rem">${esc(label)}</span>`;
}
function dcqEtiquetaChequera(ch) {
  if (!ch) return '—';
  const partes = [ch.cuenta_nombre];
  if (ch.banco_nombre) partes.push(ch.banco_nombre);
  partes.push(DCCH_CLASE_META[ch.clase]?.label || ch.clase);
  partes.push(DCCH_TIPO_META[ch.tipo]?.label   || ch.tipo);
  return partes.filter(Boolean).join(' · ');
}

const ch = { id:3, cuenta_id:109, nombre:'Vortec | Banco San Juan | Alvarez', clase:'papel', tipo:'diferido' };
const chE = { ...ch, clase:'electronica', tipo:'comun' };
console.log('badge papel      :', dcchClaseBadge('papel'));
console.log('badge electronica:', dcchClaseBadge('electronica'));
console.log('badge desconocida:', dcchClaseBadge('xx'));
console.log('etiqueta dcch    :', dcchEtiquetaChequera(ch));
console.log('etiqueta dcch E  :', dcchEtiquetaChequera(chE));
console.log('etiqueta dcch nul:', dcchEtiquetaChequera(null));
console.log('pildora papel    :', dcqClasePildora('papel'));
console.log('pildora electron :', dcqClasePildora('electronica'));
console.log('pildora null     :', JSON.stringify(dcqClasePildora(null)));
console.log('etiqueta dcq     :', dcqEtiquetaChequera({cuenta_nombre:'Vortec', banco_nombre:'Banco San Juan', clase:'electronica', tipo:'comun'}));
