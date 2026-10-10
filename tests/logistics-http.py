import os, urllib.request, urllib.error, http.cookiejar, json, re, subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
base='http://127.0.0.1:8790'
jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)); checks=0
def request(path,data=None,csrf=None,extra_headers=None):
    headers=dict(extra_headers or {})
    if isinstance(data,dict):
        data=json.dumps(data).encode();headers['Content-Type']='application/json'
    if csrf:headers['X-CSRF-Token']=csrf
    try:
        response=client.open(urllib.request.Request(base+path,data=data,headers=headers));return response.status,response.read(),response.headers
    except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
def check(ok,label):
    global checks
    assert ok,label;checks+=1;print('PASS:',label)
status,body,headers=request('/api/logistics.php');check(status==401,'anonymous calendar API denied');check('HttpOnly' in headers.get('Set-Cookie','') and 'SameSite=Lax' in headers.get('Set-Cookie',''),'company session cookie protections enabled')
status,body,_=request('/company-login.php');csrf=re.search(rb'name="csrf" value="([^"]+)"',body).group(1).decode()
import urllib.parse
def form(path,values):return request(path,urllib.parse.urlencode(values).encode())
status,body,_=form('/company-login.php',{'csrf':csrf,'email':'electrical@example.invalid','password':'Disposable-deliveries-test-only!'})
context=json.loads(request('/api/logistics.php')[1]);check(context['ok'] and not context['user']['admin'],'company session established')
csrf=context['csrf'];calendar=json.loads(request('/api/logistics.php?op=calendar&site=1&from=2026-10-12&to=2026-10-19')[1])['items'];private=[x for x in calendar if x['private']]
check(len(private)==3 and all('id' not in x and 'supplier' not in x for x in private),'calendar redacts historical and other company bookings')
check(request('/api/logistics.php?op=detail&id=3')[0]==403,'other company details denied')
check(request('/get_week_deliveries.php?week=2026-10-12')[0]==403,'legacy feed cannot bypass company scope')
check(request('/admin/')[0]==200 and b'Admin Login' in request('/admin/')[1] or b'Login' in request('/admin/')[1],'company cannot open legacy admin tools')
check(request('/api/logistics.php?op=cancel',{'id':2,'revision':1})[0]==403,'missing CSRF denied')
check(request('/api/logistics.php?op=cancel',{'id':3,'revision':1},csrf)[0]==403,'other company cancellation denied')
status,body,_=request('/logistics-report.php?site=1&from=2026-10-12&to=2026-10-19&type=csv');check(status==200 and b'Cable drums' in body and b'Plasterboard' not in body and b'Historical Contractor' not in body,'company CSV contains only own deliveries')
status,body,_=request('/logistics-report.php?site=1&from=2026-10-12&to=2026-10-19&type=pdf');check(status==200 and body.startswith(b'%PDF-'),'private PDF run sheet generated')
import base64
image=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/5ioAAAAASUVORK5CYII=')
def upload(delivery):
    boundary='deliveries-disposable-fixture'
    data=(f'--{boundary}\r\nContent-Disposition: form-data; name="delivery_id"\r\n\r\n{delivery}\r\n--{boundary}\r\nContent-Disposition: form-data; name="sheet"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n').encode()+image+(f'\r\n--{boundary}--\r\n').encode()
    return request('/upload_delivery_sheet.php',data,csrf,{'Content-Type':'multipart/form-data; boundary='+boundary})
status,body,_=upload(2);uploaded=json.loads(body);check(status==200 and uploaded.get('ok'),'company can attach a delivery sheet to its own booking')
files=json.loads(request('/list_delivery_files.php?id=2')[1]);check(isinstance(files,list) and files[0]['url'].startswith('/private-file.php?'),'legacy attachment list keeps its array and private URL contract')
check(request(files[0]['url'])[0]==200 and request('/list_delivery_files.php?id=3')[0]==403,'delivery documents respect company ownership')
check(upload(3)[0]==403,'company cannot upload to another company booking')
change={'delivery_id':2,'requester_name':'Electrician','request_type':'edit_details','details':'Disposable change request','csrf':csrf}
check(json.loads(form('/request_change.php',change)[1]).get('ok'),'company can request changes to its own booking');change['delivery_id']=3;check(form('/request_change.php',change)[0]==403,'company cannot request changes to another company booking')
payload={'site_id':1,'company_id':3,'gate_id':3,'resource_id':3,'due_datetime':'2026-10-12 10:00','duration_min':20,'supplier':'Spoofed','material':'Own booking','quantity':'1','user_name':'Spoofed'}
status,body,_=request('/api/logistics.php?op=save',payload,csrf);new=json.loads(body);check(status==200 and int(new['company_id'])==2 and new['supplier']=='Acme Electrical','HTTP booking cannot spoof company ownership')
results=list(ThreadPoolExecutor(max_workers=4).map(lambda _:subprocess.check_output([os.environ.get('PHP_BINARY',r'C:\xampp\php\php.exe' if os.name=='nt' else 'php'),str(Path(__file__).with_name('mysql-assertions.php')),'--worker']).decode(),range(4)))
check(results.count('created')==1 and results.count('conflict')==3,'concurrent MySQL reservations allow exactly one booking for a shared resource')
print(checks,'HTTP/concurrency checks passed')
