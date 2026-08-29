const mqtt = require('mqtt');

console.log('Testing ws://karsacerdasinovatif.web.id:8081');
const clientWS = mqtt.connect('ws://karsacerdasinovatif.web.id:8081', { connectTimeout: 3000 });
clientWS.on('connect', () => { console.log('WS Connected!'); clientWS.end(); });
clientWS.on('error', (err) => { console.log('WS Error:', err.message); });

console.log('Testing mqtt://karsacerdasinovatif.web.id:1883');
const clientMQTT = mqtt.connect('mqtt://karsacerdasinovatif.web.id:1883', { connectTimeout: 3000 });
clientMQTT.on('connect', () => { console.log('MQTT Connected!'); clientMQTT.end(); });
clientMQTT.on('error', (err) => { console.log('MQTT Error:', err.message); });
