const mqtt = require('mqtt');

// Connect to MQTT Broker
const brokerUrl = process.env.MQTT_BROKER_URL || 'wss://karsacerdasinovatif.web.id:8081';
const mqttClient = mqtt.connect(brokerUrl);

mqttClient.on('connect', () => {
    console.log('Connected to MQTT Broker for EWS Control');
});

mqttClient.on('error', (err) => {
    console.error('MQTT Connection Error:', err);
});

const ewsControl = (req, res) => {
    try {
        const payload = req.body;
        
        if (!payload || !payload.device_id) {
            return res.status(400).json({ 
                status: false, 
                message: 'device_id is required in JSON payload' 
            });
        }

        const deviceId = payload.device_id;
        
        // Remove device_id from payload before sending to MQTT
        const mqttPayload = { ...payload };
        delete mqttPayload.device_id;
        
        const topic = `temins_iot/${deviceId}/control`;
        
        mqttClient.publish(topic, JSON.stringify(mqttPayload), { qos: 1 }, (err) => {
            if (err) {
                console.error('MQTT Publish Error:', err);
                return res.status(500).json({ 
                    status: false, 
                    message: 'Failed to publish to MQTT broker' 
                });
            }
            
            return res.json({ 
                status: true, 
                message: 'Data successfully published to MQTT',
                topic: topic,
                data_published: mqttPayload
            });
        });
    } catch (error) {
        console.error('EWS Control Error:', error);
        res.status(500).json({ status: false, message: 'Internal Server Error' });
    }
};

module.exports = {
    ewsControl
};
