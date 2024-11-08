const express = require('express');
const mongoose = require('mongoose');
const bodyParser = require('body-parser');
const bcrypt = require('bcryptjs');
const cors = require('cors');
const path = require('path');
const User = require('./models/User'); // Import model người dùng

const app = express();

/// Kết nối tới MongoDB
mongoose.connect('mongodb://localhost:27017/thuctap', {
    useNewUrlParser: true,
    useUnifiedTopology: true,
})
.then(() => console.log('Đã kết nối tới MongoDB'))
.catch(err => console.error('Lỗi kết nối MongoDB:', err));
// Middleware để parse JSON và dữ liệu URL-encoded
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
// Sử dụng model người dùng
app.post('/register', async (req, res) => {
    const { username, email, password } = req.body;

    // Kiểm tra dữ liệu đầu vào
    if (!username || !email || !password) {
        return res.status(400).json({ error: 'Vui lòng điền đầy đủ thông tin.' });
    }

    try {
        // Kiểm tra xem người dùng đã tồn tại hay chưa
        const existingUser = await User.findOne({ email });
        if (existingUser) {
            return res.status(400).json({ error: 'Email đã được sử dụng.' });
        }

        // Tạo người dùng mới
        const newUser = new User({ username, email, password });
        await newUser.save(); // Lưu người dùng vào MongoDB

        res.status(201).json({ message: 'Đăng ký thành công!' });
    } catch (error) {
        console.error('Lỗi khi đăng ký người dùng:', error);
        res.status(500).json({ error: 'Lỗi hệ thống, vui lòng thử lại sau.' });
    }
});


// Cấu hình EJS làm engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views')); // Đường dẫn đến thư mục views

// Để phục vụ các tệp tĩnh từ thư mục 'public'
app.use(express.static(path.join(__dirname, 'public')));

// Sử dụng body-parser
app.use(bodyParser.urlencoded({ extended: true }));

// Route cho đường dẫn gốc
app.get('/', (req, res) => {
    res.render('index');
});
// Route cho trang Features
app.get('/shoping-cart', (req, res) => {
    res.render('shoping-cart');
});
// Route cho trang shop
app.get('/product', (req, res) => {
    res.render('product');
});
// Route cho trang blog
app.get('/blog', (req, res) => {
    res.render('blog');
});
// Route cho trang about
app.get('/about', (req, res) => {
    res.render('about');
});
// Route cho trang contact
app.get('/contact', (req, res) => {
    res.render('contact');
});
// Route xử lý đăng nhập
// Route cho trang đăng nhập
app.get('/login', (req, res) => {
    res.render('login'); // Render tệp login.ejs hoặc bạn có thể sử dụng tệp HTML bạn cung cấp
});

// Route xử lý đăng nhập
app.post('/login', (req, res) => {
    const { username, password } = req.body;

    // Giả sử bạn có tài khoản người dùng cố định
    const mockUser = { username: 'admin', password: '1234' };

    if (username === mockUser.username && password === mockUser.password) {
        // Nếu đăng nhập thành công, chuyển hướng tới trang chính
        res.redirect('/');
    } else {
        // Nếu thất bại, chuyển hướng lại trang đăng nhập
        res.redirect('/login');
    }
});

// Định nghĩa route cho home-02.ejs
app.get('/home-02', (req, res) => {
    res.render('home-02');
});
app.use((req, res, next) => {
    console.log(`Request Method: ${req.method}, Request URL: ${req.url}`);
    next();
});


// Khởi động server
const PORT = 5000;
app.listen(PORT, () => {
    console.log(`Server chạy trên cổng ${PORT}`);
});
