import sys, zipfile
from xml.sax.saxutils import escape
def build(path, rows):
    def cell(r,c,v):
        ref=chr(65+c)+str(r+1)
        if isinstance(v,(int,float)): return f'<c r="{ref}"><v>{v}</v></c>'
        if isinstance(v,str) and v.startswith('='): return f'<c r="{ref}"><f>{escape(v[1:])}</f><v>0</v></c>'
        return f'<c r="{ref}" t="inlineStr"><is><t>{escape(str(v))}</t></is></c>'
    data=''.join(f'<row r="{i+1}">'+''.join(cell(i,j,v) for j,v in enumerate(r))+'</row>' for i,r in enumerate(rows))
    z=zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED)
    z.writestr('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>')
    z.writestr('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>')
    z.writestr('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Names" sheetId="1" r:id="rId1"/></sheets></workbook>')
    z.writestr('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>')
    z.writestr('xl/worksheets/sheet1.xml',f'<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>{data}</sheetData></worksheet>')
    z.close()
ts=sys.argv[1]; d=sys.argv[2]
H=['Domain','Option','Price','Min offer']
rows=[H]
for i in range(1,19): rows.append([f'qa-e2e-x{i}-{ts}.ca','buy_now',2000+i*100,500])
rows+= [[f'qa-e2e-x1-{ts}.ca','buy_now',9999,0],           # duplicate in file
        [f'qa-e2e-bad1-{ts}.ca','buy_now','abc',0],          # text price
        [f'qa-e2e-bad2-{ts}.ca','buy_now',50,0],             # below minimum
        [f'qa-e2e-bad3-{ts}.ca','buy_now',1000,5000],        # min above price
        ['=HYPERLINK("http://evil.example","x")','buy_now',2000,0],  # formula
        ['not a domain','buy_now',2000,0],
        [f'https://www.QA-E2E-URL-{ts}.ca/path?a=1','make_offer',0,800]]
build(f'{d}/bulk25.xlsx',rows)
big=[H]+[[f'qa-e2e-big{i}-{ts}.ca','buy_now',2000,0] for i in range(1005)]
build(f'{d}/big.xlsx',big)
sync=[H]+[[f'qa-e2e-x{i}-{ts}.ca','buy_now',3000,0] for i in range(1,11)]
build(f'{d}/sync.xlsx',sync)
open(f'{d}/fake.xlsx','wb').write(b'MZ\x90\x00'+b'\x00'*2000)
open(f'{d}/huge.csv','wb').write((b'domain,price\n'+b'qa-pad.ca,2000\n'*450000))
