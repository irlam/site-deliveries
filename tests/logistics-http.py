import os, urllib.request, urllib.error, http.cookiejar, json, re, subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
base='http://127.0.0.1:8790'
jar=http.cookiejar.CookieJar(); client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)); checks=0
def request(path,data=None,csrf=None):
    headers={}
    if isinstance(data,dict):
        data=json.dumps(data).encode();headers['Content-Type']='application/json'
    if csrf:headers['X-CSRF-Token']=csrf
    try:
        response=client.open(urllib.request.Request(base+path,data=data,headers=headers));return response.status,response.read(),response.headers
    except urllib.error.HTTPError as error:return error.code,error.read(),error.headers
def check(ok,label):
    global checks
    assert ok,label;checks+=1;print('PASS:',label)
status,body,_=request('/api/logistics.php');check(status==401,'anonymous calendar API denied')
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
payload={'site_id':1,'company_id':3,'gate_id':3,'resource_id':3,'due_datetime':'2026-10-12 10:00','duration_min':20,'supplier':'Spoofed','material':'Own booking','quantity':'1','user_name':'Spoofed'}
status,body,_=request('/api/logistics.php?op=save',payload,csrf);new=json.loads(body);check(status==200 and int(new['company_id'])==2 and new['supplier']=='Acme Electrical','HTTP booking cannot spoof company ownership')
results=list(ThreadPoolExecutor(max_workers=4).map(lambda _:subprocess.check_output([os.environ.get('PHP_BINARY',r'C:\xampp\php\php.exe' if os.name=='nt' else 'php'),str(Path(__file__).with_name('mysql-assertions.php')),'--worker']).decode(),range(4)))
check(results.count('created')==1 and results.count('conflict')==3,'concurrent MySQL reservations allow exactly one booking for a shared resource')
print(checks,'HTTP/concurrency checks passed')
