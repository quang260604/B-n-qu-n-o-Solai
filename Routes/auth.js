// Routes/auth.js
const express = require('express');
const bcrypt = require('bcrypt');
const User = require('../models/User');

const router = express.Router();

// Route cho trang đăng ký
router.get('/register', (req, res) => {
    res.render('register', { message: req.flash('message') });
});

// Route xử lý đăng ký
router.post('/register', async (req, res) => {
    const { username, email, password } = req.body;
    const hashedPassword = await bcrypt.hash(password, 10);

    const user = new User({ username, email, password: hashedPassword });

    try {
        await user.save();
        req.flash('message', 'Đăng ký thành công! Vui lòng đăng nhập.');
        res.redirect('/login');
    } catch (error) {
        req.flash('message', 'Lỗi! Username hoặc email đã tồn tại.');
        res.redirect('/register');
    }
});

// Route cho trang đăng nhập
router.get('/login', (req, res) => {
    res.render('login', { message: req.flash('message') });
});

// Route xử lý đăng nhập
router.post('/login', async (req, res) => {
    const { username, password } = req.body;

    const user = await User.findOne({ username });

    if (user && await bcrypt.compare(password, user.password)) {
        req.flash('message', 'Đăng nhập thành công!');
        res.redirect('/'); // Chuyển hướng đến trang chính sau khi đăng nhập thành công
    } else {
        req.flash('message', 'Sai tài khoản hoặc mật khẩu.');
        res.redirect('/login');
    }
});

module.exports = router;
