require('dotenv').config();
const express = require('express');
const app = express();
const dotenv = require('dotenv');
const cors = require('cors');

dotenv.config();

app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.get('/', (req, res) => {
  res.send('Node.js Backend is running');
});

const authRoutes = require('./routes/auth');
app.use('/api-app/auth', authRoutes);

const userRoutes = require('./routes/user');
app.use('/api-app/user', userRoutes);

const awsRoutes = require('./routes/user/aws');
app.use('/api-app/user/aws', awsRoutes);

const awlrRoutes = require('./routes/user/awlr');
app.use('/api-app/user/awlr', awlrRoutes);

const sfRoutes = require('./routes/user/sf');
app.use('/api-app/user/sf', sfRoutes);

const instansiAwsRoutes = require('./routes/instansi/aws');
app.use('/api-app/instansi/aws', instansiAwsRoutes);

const adminDsRoutes = require('./routes/admin/ds');
app.use('/api-app/admin/ds.php', adminDsRoutes);

const adminSetRoutes = require('./routes/admin/settings');
app.use('/api-app/admin/admin-set.php', adminSetRoutes);

const adminDevRoutes = require('./routes/admin/dev');
app.use('/api-app/admin/dev.php', adminDevRoutes);

const adminInstansiRoutes = require('./routes/admin/instansi');
app.use('/api-app/admin/instansi.php', adminInstansiRoutes);

const adminSetRecRoutes = require('./routes/admin/set_rec');
app.use('/api-app/admin/set_rec.php', adminSetRecRoutes);

const ewsRoutes = require('./routes/ews');
app.use('/api/v1/ews', ewsRoutes);

const PORT = process.env.PORT || 4000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
