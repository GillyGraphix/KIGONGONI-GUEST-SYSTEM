<?php
session_start();
include 'config.php'; /
$book_id = $_POST['book_id'] ?? null;

if ($book_id) {
    
    $stmt = $conn->prepare("SELECT title, author, price FROM books WHERE book_id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $book = $result->fetch_assoc()) {
        
        $bookItem = [
            'book_id' => $book_id,
            'title' => $book['title'],
            'author' => $book['author'],
            'price' => $book['price'],
            'quantity' => 1
        ];

        /
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        
        $found = false;
        foreach ($_SESSION['cart'] as &$item) {
            if ($item['book_id'] == $book_id) {
                $item['quantity'] += 1;
                $found = true;
                break;
            }
        }
        unset($item);

        
        if (!$found) {
            $_SESSION['cart'][] = $bookItem;
        }

        echo " Book added to cart successfully!<br><a href='browse_category.php'> Back to categories</a>";
    } else {
        echo " Book not found.<br><a href='browse_category.php'> Back to categories</a>";
    }
} else {
    echo " No book selected.<br><a href='browse_category.php'> Back to categories</a>";
}
?>
