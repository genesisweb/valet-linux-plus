<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $siteName ?></title>
    <style>
        html, body {
            padding: 0;
            margin: 0;
            font-family: monospace, sans-serif;
            line-height: 1.45;
            font-size: 16px;
            letter-spacing: 0;
            position: relative;
        }

        ul {
            padding: 0;
            margin: 0;
            list-style: none;
            text-align: left;
        }

        ul > li {
            display: block;
            margin: 10px 15px;
        }

        ul > li > a {
            text-decoration: none;
            display: block;
            color: #2d2d2d;
            transition: all .15s linear;
            padding: 0 0 5px;
        }

        ul > li > a:hover {
        }

        ul > li > a:visited {
            color: #0042bd;
            border-color: #0042bd;
        }

        ul > li > a:before {
            content: "";
            height: 20px;
            width: 20px;
            display: inline-block;
            text-align: center;
            background: url('data:image/svg+xml;utf8;base64,PD94bWwgdmVyc2lvbj0iMS4wIj8+CjxzdmcgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBoZWlnaHQ9IjUxMnB4IiB2aWV3Qm94PSIwIDAgNjQgNjQiIHdpZHRoPSI1MTJweCI+PGcgaWQ9IkZvbGRlciI+PHBhdGggZD0ibTUzIDI3di04YTQgNCAwIDAgMCAtNC00aC0xOC4zNDNhNCA0IDAgMCAxIC0yLjgyOS0xLjE3MmwtMy42NTYtMy42NTZhNCA0IDAgMCAwIC0yLjgyOS0xLjE3MmgtMTQuMzQzYTQgNCAwIDAgMCAtNCA0djQyaDUweiIgZmlsbD0iI2RkYjIwMCIvPjxwYXRoIGQ9Im00NSAyMWgtMzRhMiAyIDAgMCAwIC0yIDJ2MTUuMjIxbDMuMDU4LTguNTUyYTQgNCAwIDAgMSAzLjc3Mi0yLjY2OWgzMS4xN3YtNGEyIDIgMCAwIDAgLTItMnoiIGZpbGw9IiNkMWU3ZjgiLz48cGF0aCBkPSJtNTcuMzQ2IDI3aC00MS41MTZhNCA0IDAgMCAwIC0zLjc3MiAyLjY2OWwtMy4wNTggOC41NTItNiAxNi43Nzl2MmE0IDQgMCAwIDAgNCA0aDQxLjE3YTQgNCAwIDAgMCAzLjc3Mi0yLjY2OWw5LjE3Ni0yNmE0IDQgMCAwIDAgLTMuNzcyLTUuMzMxeiIgZmlsbD0iI2ZmZGE0NCIvPjxwYXRoIGQ9Im01Ny4zNDYgMjdoLTQuMTMzYy0uMDI5LjExLS4wNTYuMjIxLS4wOTUuMzMxbC05LjE3NiAyNmE0IDQgMCAwIDEgLTMuNzcyIDIuNjY5aC0zNy4xN3YxYTQgNCAwIDAgMCA0IDRoNDEuMTdhNCA0IDAgMCAwIDMuNzcyLTIuNjY5bDkuMTc2LTI2YTQgNCAwIDAgMCAtMy43NzItNS4zMzF6IiBmaWxsPSIjZmZjZDAwIi8+PC9nPjwvc3ZnPgo=');
            background-size: 100% 100%;
            background-repeat: no-repeat;
            background-position: center center;
            margin: 0 10px 0 0;
            vertical-align: middle;
        }
        ul > li.file > a:before {
            background-image: url(data:image/svg+xml;utf8;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iaXNvLTg4NTktMSI/Pgo8IS0tIEdlbmVyYXRvcjogQWRvYmUgSWxsdXN0cmF0b3IgMTguMS4xLCBTVkcgRXhwb3J0IFBsdWctSW4gLiBTVkcgVmVyc2lvbjogNi4wMCBCdWlsZCAwKSAgLS0+CjxzdmcgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgdmVyc2lvbj0iMS4xIiBpZD0iQ2FwYV8xIiB4PSIwcHgiIHk9IjBweCIgdmlld0JveD0iMCAwIDQxLjk1MyA0MS45NTMiIHN0eWxlPSJlbmFibGUtYmFja2dyb3VuZDpuZXcgMCAwIDQxLjk1MyA0MS45NTM7IiB4bWw6c3BhY2U9InByZXNlcnZlIiB3aWR0aD0iNjRweCIgaGVpZ2h0PSI2NHB4Ij4KPGc+Cgk8Zz4KCQk8cGF0aCBkPSJNNS4zNDQsMTQuNDg3aDcuODgzYzAuOTM1LDAsMS42OTQtMC43NjEsMS42OTQtMS42OTVWMS43OTdoMTcuMzU5VjExLjk1aDEuNzk3VjEuNjk0ICAgIEMzNC4wNzYsMC43NTksMzMuMzE1LDAsMzIuMzgyLDBIMTQuODE5QzE0LjEsMCwxMy4yNiwwLjQxNywxMi44MjUsMC45OTNMNC4yMDcsMTIuNDEyYy0wLjM4MywwLjUwOC0wLjY2LDEuMzM4LTAuNjYsMS45NzR2MjMuNTQ4ICAgIGMwLDAuOTM1LDAuNzYsMS42OTQsMS42OTQsMS42OTRoMjEuMTA0di0xLjc5Nkg1LjM0NFYxNC40ODd6IE0xMy4xMjUsMy41OHY5LjExSDYuMjQ5TDEzLjEyNSwzLjU4eiIgZmlsbD0iIzAwMDAwMCIvPgoJCTxwYXRoIGQ9Ik0zMC40MzQsMzUuMjk4Yy0xLjg1MSwwLTMuMTkxLDEuNDA2LTMuMTkxLDMuMzQ2YzAsMS44ODcsMS4zNTUsMy4zMDksMy4xNTIsMy4zMDkgICAgYzEuODcxLDAsMy4yMy0xLjM5MSwzLjIzLTMuMzA5QzMzLjYyNSwzNi43MDQsMzIuMjgzLDM1LjI5OCwzMC40MzQsMzUuMjk4eiBNMzAuMzk0LDQwLjE1NGMtMC43OTksMC0xLjM1Ni0wLjYyMS0xLjM1Ni0xLjUxMSAgICBjMC0wLjkyOCwwLjU2Mi0xLjU1MSwxLjM5Ni0xLjU1MWMwLjg3MywwLDEuMzk1LDAuNTgsMS4zOTUsMS41NTFDMzEuODI4LDM5LjU3NSwzMS4yNzcsNDAuMTU0LDMwLjM5NCw0MC4xNTR6IiBmaWxsPSIjMDAwMDAwIi8+CgkJPHBhdGggZD0iTTMxLjAxOCwxMy4wNjRjLTEuODQ1LDAtMy44MjQsMC40NjYtNS4yOTcsMS4yNDVjLTAuNzg1LDAuNDE1LTEuMTQ1LDEuNDEzLTAuODE4LDIuMjcxbDAuMzY1LDAuOTYgICAgYzAuMzIxLDAuODQ2LDEuMzc5LDEuMjMyLDIuMjEzLDAuNzk5YzAuODE0LTAuNDI2LDEuODczLTAuNjgsMi44MjItMC42OGMxLjg2MywwLjAyOSwyLjgwOSwwLjg1MiwyLjgwOSwyLjQ0MyAgICBjMCwxLjQ5OS0wLjkyLDIuODc1LTIuNTEyLDQuNzVjLTEuOTg4LDIuMzg0LTIuODkyLDQuOTM4LTIuNjE1LDcuMzY3bDAuMDM3LDAuNTA2YzAuMDYsMC43NjEsMC44MDQsMS4zMzQsMS43MywxLjMzNGgxLjQ3OSAgICBjMC41MzMsMCwxLjAxNi0wLjE5LDEuMzIyLTAuNTIyYzAuMjM5LTAuMjYxLDAuMzU3LTAuNTkyLDAuMzMtMC45MzVsLTAuMDM3LTAuNDY3Yy0wLjEwNC0xLjgwMiwwLjQ5NC0zLjMzMiwyLjAwNi01LjEyMSAgICBjMi4wMTUtMi4zOTEsMy41NTUtNC40NDgsMy41NTUtNy4zNEMzOC40MDUsMTYuMzg2LDM2LjEyMSwxMy4wNjQsMzEuMDE4LDEzLjA2NHogTTMzLjQ3OCwyNS44NTkgICAgYy0xLjgwOSwyLjEzOC0yLjU1Nyw0LjEwNi0yLjQyNCw2LjQwNWgtMS4yNjdsLTAuMDE2LTAuMjEyYy0wLjIyMy0xLjk1NywwLjUzOS00LjA0OCwyLjE5OS02LjA0MSAgICBjMS44NTgtMi4xODcsMi45MzYtMy44NTQsMi45MzYtNS45MDdjMC0yLjU3MS0xLjc1Ni00LjE5Ni00LjU4MS00LjI0aC0wLjAwOWMtMS4xNjQsMC0yLjQ0NywwLjI5My0zLjQ1OSwwLjgwN2wtMC4yOTktMC43NzMgICAgYzEuMjI4LTAuNjQ4LDIuODk1LTEuMDM2LDQuNDU3LTEuMDM2YzQuMTIzLDAsNS41OTIsMi40ODcsNS41OTIsNC44MTVDMzYuNjA3LDIxLjk2OCwzNS4zMTEsMjMuNjg0LDMzLjQ3OCwyNS44NTl6IiBmaWxsPSIjMDAwMDAwIi8+Cgk8L2c+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPGc+CjwvZz4KPC9zdmc+Cg==);
        }
        h1 {
            text-align: center;
            margin: 15px 0 15px;
            border-bottom: 1px solid #ccc;
            line-height: 1.6;
            padding: 0px 0 10px;
        }

        @media (max-width: 767px) {
            h1 {
                font-size: 25px;
            }

            ul > li > a {
                padding: 5px 15px;
            }
        }

        @media (max-width: 479px) {
            h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
<h1>Index of <?php echo $uri; ?></h1>

<?php if (isset($directory)) { ?>
    <ul>
        <?php
        $handle = opendir($directory.$uri);
        if ($_SERVER['SERVER_ADDR'] !== '127.0.0.1' && !substr($uri, 0, -1)) {
            echo '<li class="folder"><a href="/valet-sites">Valet Sites</a></li>';
        }
        if (substr($uri, -1) !== '/') {
            $uri .= '/';
        }
        while ($file = readdir($handle)) {
            if (!in_array($file, $ignoredPaths)) {
                if (is_dir($directory.$uri.$file)) {
                    echo '<li class="folder"><a href="'.$uri.$file.'/">'.$file.'</a></li>';
                }
            }
        }
        $handle = opendir($directory.$uri);
        while ($file = readdir($handle)) {
            if (!in_array($file, $ignoredPaths)) {
                if (!is_dir($directory.$uri.$file)) {
                    echo '<li class="file"><a href="'.$uri.$file.'">'.$file.'</a></li>';
                }
            }
        }
        ?>
    </ul>
<?php } else { ?>
    <span style="display: inline-block;padding: 0 10px;">Directory looks empty!</span>
<?php } ?>
</body>
</html>
