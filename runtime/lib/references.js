'use strict';

const SSM = 'arco-ssm:';
const SECRET = 'arco-secret:';

function isReference(value) {
  return typeof value === 'string' && (value.startsWith(SSM) || value.startsWith(SECRET));
}

function parseReference(value) {
  if (value.startsWith(SSM)) {
    const name = value.slice(SSM.length);
    if (name === '') throw new Error(`Invalid secret reference "${value}": the parameter name is empty.`);
    if (name.includes('#')) throw new Error(`Invalid secret reference "${value}": "#key" is only supported for arco-secret: references.`);
    return { source: 'ssm', name, jsonKey: null };
  }
  if (value.startsWith(SECRET)) {
    const rest = value.slice(SECRET.length);
    const index = rest.indexOf('#');
    const name = index === -1 ? rest : rest.slice(0, index);
    const jsonKey = index === -1 ? null : rest.slice(index + 1);
    if (name === '') throw new Error(`Invalid secret reference "${value}": the secret id is empty.`);
    if (jsonKey === '') throw new Error(`Invalid secret reference "${value}": the JSON key after "#" is empty.`);
    return { source: 'secretsmanager', name, jsonKey };
  }
  throw new Error(`"${value}" is not an arco-ssm: or arco-secret: reference.`);
}

module.exports = { isReference, parseReference };
