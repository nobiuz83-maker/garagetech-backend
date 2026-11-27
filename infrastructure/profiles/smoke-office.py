"""Exercise the OFFICE HTTPS workflow with new fictional records only."""
import argparse
import json
from pathlib import Path
from urllib.parse import unquote
from uuid import uuid4

import requests

WORKSPACE = Path(__file__).resolve().parents[2] / '.private' / 'office'
ORIGIN = 'https://localhost:8443'
RECORDS = WORKSPACE / 'smoke-records.json'


def authenticated_session() -> tuple[requests.Session, str]:
    account_document = json.loads((WORKSPACE / 'accounts.json').read_text())
    owner = next(account for account in account_document['accounts'] if account['name'] == 'Synthetic Owner A')
    company_id = account_document['companies'][0]['id']
    session = requests.Session()
    session.verify = str(WORKSPACE / 'authority/cert.pem')
    session.headers.update({'Origin': ORIGIN, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-Company-ID': company_id})
    csrf_response = session.get(ORIGIN + '/sanctum/csrf-cookie', timeout=15)
    csrf_response.raise_for_status()
    session.headers['X-XSRF-TOKEN'] = unquote(session.cookies.get('XSRF-TOKEN') or '')
    login_response = session.post(ORIGIN + '/login', json={'email': owner['email'], 'password': owner['password']}, timeout=15)
    login_response.raise_for_status()
    session.headers['X-XSRF-TOKEN'] = unquote(session.cookies.get('XSRF-TOKEN') or '')
    user_response = session.get(ORIGIN + '/api/user', timeout=15)
    user_response.raise_for_status()
    return session, company_id


def send(session: requests.Session, method: str, path: str, payload: dict | None = None) -> dict:
    headers = {'Idempotency-Key': str(uuid4())} if method != 'GET' else {}
    response = session.request(method, ORIGIN + path, json=payload, headers=headers, timeout=20)
    if not response.ok:
        raise RuntimeError(f'{method} {path}: HTTP {response.status_code}: {response.text[:450]}')
    return response.json()


def create_records(session: requests.Session) -> dict:
    if RECORDS.exists():
        raise RuntimeError('OFFICE smoke records already exist; use --verify.')
    client = send(session, 'POST', '/api/v1/clients', {
        'firstName': 'Essai', 'lastName': 'Bureau', 'phone': None, 'email': None,
        'vehicles': [{'make': 'Toyota', 'model': 'Corolla', 'year': 2020, 'vin': None, 'licensePlate': None}],
    })['data']
    client_id = client['id']
    vehicle_id = client['vehicles'][0]['id']
    intervention_id = str(uuid4())
    send(session, 'POST', '/api/v1/interventions', {
        'id': intervention_id, 'clientId': client_id, 'vehicleId': vehicle_id,
        'startDate': '2026-11-18', 'description': 'Diagnostic fictif OFFICE',
        'diagnosis': '', 'notes': '', 'laborRateCents': 10000, 'laborHours': '1.0000',
    })
    invoice_id = str(uuid4())
    invoice_details = {key: None for key in [
        'sellerLegalName', 'sellerAddress', 'sellerPhone', 'sellerEmail',
        'gstNumber', 'qstNumber', 'customerAddress', 'customerPhone',
        'customerEmail', 'vehicleVin', 'vehiclePlate',
    ]}
    invoice_details['paymentTerms'] = 'Payable immédiatement'
    send(session, 'POST', '/api/v1/invoices', {
        'id': invoice_id, 'interventionId': intervention_id, 'gstRate': 0, 'qstRate': 0,
        'sellerName': 'ViCarTech OFFICE fictif', 'customerName': 'Essai Bureau',
        'vehicleDescription': '2020 Toyota Corolla', 'details': invoice_details,
    })
    payment_id = str(uuid4())
    send(session, 'POST', f'/api/v1/invoices/{invoice_id}/payments', {
        'id': payment_id, 'amountCents': 10000, 'method': 'cash',
    })
    records = {'clientId': client_id, 'vehicleId': vehicle_id, 'interventionId': intervention_id,
               'invoiceId': invoice_id, 'paymentId': payment_id}
    RECORDS.write_text(json.dumps(records, indent=2))
    RECORDS.chmod(0o600)
    return records


def verify_records(session: requests.Session, records: dict) -> None:
    clients = send(session, 'GET', '/api/v1/clients')['data']
    assert any(client['id'] == records['clientId'] and any(vehicle['id'] == records['vehicleId'] for vehicle in client['vehicles']) for client in clients)
    interventions = send(session, 'GET', '/api/v1/interventions')['data']
    assert any(intervention['id'] == records['interventionId'] for intervention in interventions)
    invoice = send(session, 'GET', f"/api/v1/invoices/{records['invoiceId']}")['data']
    assert invoice['id'] == records['invoiceId']
    assert any(payment['id'] == records['paymentId'] for payment in invoice['payments'])
    assert invoice['paidCents'] == 10000 and invoice['balanceCents'] == 0
    print('OFFICE HTTPS: client, vehicle, intervention, invoice and payment persisted.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--create', action='store_true')
    parser.add_argument('--verify', action='store_true')
    arguments = parser.parse_args()
    if arguments.create == arguments.verify:
        parser.error('Choose exactly one of --create or --verify')
    current_session, _ = authenticated_session()
    saved_records = create_records(current_session) if arguments.create else json.loads(RECORDS.read_text())
    verify_records(current_session, saved_records)
