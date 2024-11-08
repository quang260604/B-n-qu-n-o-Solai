app.get('/users', async (req, res) => {
    try {
        const users = await User.find();  // Lấy danh sách người dùng từ MongoDB
        res.render('login', { users: users });  // Render view index.ejs và truyền dữ liệu
    } catch (err) {
        res.status(500).send("Lỗi server");
    }
});

