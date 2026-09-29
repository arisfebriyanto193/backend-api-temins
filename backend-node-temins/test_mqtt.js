const mqtt = require('mqtt');

console.log('Testing ws://temins.my.id:9001');
const clientWS = mqtt.connect('ws://temins.my.id:9001', { connectTimeout: 3000 });
clientWS.on('connect', () => { console.log('WS Connected to temins.my.id:9001!'); clientWS.end(); });
clientWS.on('error', (err) => { console.log('WS Error:', err.message); });

console.log('Testing mqtt://temins.my.id:1883');
const clientMQTT = mqtt.connect('mqtt://temins.my.id:1883', { connectTimeout: 3000 });
clientMQTT.on('connect', () => { console.log('MQTT Connected to temins.my.id:1883!'); clientMQTT.end(); });
clientMQTT.on('error', (err) => { console.log('MQTT Error:', err.message); });

