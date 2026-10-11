"""Tests only the isolated, disposable local database and HTTP server."""
import http.cookiejar, urllib.request, urllib.error, urllib.parse, subprocess, re
from pathlib import Path
root=Path(__file__).resolve().parents[1]
seed="require $argv[1]; if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='deliveries_gate_fixture')exit(1); $pdo->exec(\"CREATE TABLE IF NOT EXISTS push_broadcasts(id INT PRIMARY KEY AUTO_INCREMENT,title VARCHAR(255),body TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)\"); $s=$pdo->prepare('INSERT INTO push_broadcasts(title,body) VALUES(?,?)'); $s->execute(['Disposable deletion fixture','Local test only']); echo $pdo->lastInsertId();"
bid=subprocess.check_output([r'C:/xampp/php/php.exe','-r',seed,str(root/'db.php')]).decode().strip()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(path,values=None):
    data=urllib.parse.urlencode(values).encode() if values is not None else None
    try:
        with client.open('http://127.0.0.1:8790'+path,data=data) as r:return r.status,r.read(),r.geturl()
    except urllib.error.HTTPError as e:return e.code,e.read(),e.url
path='/admin/delete-broadcast.php?id='+bid
assert b'Admin Login' in req(path)[1]
req('/admin/login.php',{'email':'admin@example.invalid','password':'Disposable-deliveries-test-only!'})
status,body,_=req(path);assert status==200 and b'Disposable deletion fixture' in body
csrf=re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
assert req(path,{'id':bid,'confirm':'delete','csrf':'invalid'})[0]==403
assert req(path,{'id':bid,'csrf':csrf})[0]==422
status,body,_=req(path,{'id':bid,'csrf':csrf,'confirm':'delete'});assert status==200 and b'Broadcast history entry deleted' in body
assert req(path)[0]==404
print('PASS: authentication, confirmation, CSRF and deletion verified in disposable fixture')
