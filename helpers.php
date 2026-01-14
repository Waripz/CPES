<?php
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year', 'm' => 'month', 'w' => 'week',
        'd' => 'day',  'h' => 'hour',  'i' => 'minute', 's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

/**
 * Check if user has a valid profile image
 */
function has_profile_image($profile_image) {
    return !empty($profile_image) && file_exists($profile_image);
}

/**
 * Get first letter of name for avatar
 */
function get_avatar_letter($name) {
    return strtoupper(substr(trim($name), 0, 1));
}

/**
 * Render avatar HTML - either image or letter avatar
 * @param string $profile_image - path to profile image
 * @param string $name - user's name for letter fallback
 * @param string $class - CSS class (default: 'avatar')
 * @param string $size - 'small', 'medium', 'large' (for letter avatar sizing)
 */
function render_avatar($profile_image, $name, $class = 'avatar', $size = 'small') {
    $hasImage = has_profile_image($profile_image);
    $letter = get_avatar_letter($name);
    
    if ($hasImage) {
        return '<img src="' . htmlspecialchars($profile_image, ENT_QUOTES, 'UTF-8') . '" class="' . $class . '" alt="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '">';
    } else {
        $sizeClass = 'letter-avatar';
        if ($size === 'large') $sizeClass = 'letter-avatar-large';
        elseif ($size === 'medium') $sizeClass = 'letter-avatar-medium';
        
        return '<div class="' . $sizeClass . ' ' . $class . '">' . $letter . '</div>';
    }
}
?>