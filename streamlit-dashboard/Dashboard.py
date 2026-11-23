#!/usr/bin/env python
# coding: utf-8

# In[ ]:


import pandas as pd
import plotly.express as px
import streamlit as st
import matplotlib.pyplot as plt
from matplotlib.ticker import FuncFormatter
from datetime import datetime
from zoneinfo import ZoneInfo

import requests


# In[ ]:


st.set_page_config(page_title="Dashboard de Controle",page_icon=":bar_chart:",layout="wide", initial_sidebar_state='expanded')


# In[ ]:


st.markdown('<meta charset="UTF-8">', unsafe_allow_html=True)


# In[ ]:


# A API do CIS valida a sessão, a permissão de Relatórios e a agência.
try:
    response = requests.get(
        "http://api-preview:8000/api/streamlit/dados",
        cookies={"cis_streamlit": st.context.cookies.get("cis_streamlit", "")},
        headers={"Accept": "application/json"},
        timeout=30,
        allow_redirects=False,
    )
    response.raise_for_status()
    data = response.json()
except (requests.RequestException, ValueError):
    st.error("Não foi possível consultar o Dashboard. Volte a abrir o separador no CIS.")
    st.stop()

df = pd.DataFrame.from_records(data["contractos"])
df3 = pd.DataFrame.from_records(data["clientes"], columns=["Nome Completo", "Estado Civil"])
if df.empty:
    st.info("Não há contratos disponíveis para a sua agência.")
    st.stop()

for field in ("Quantia Creditada", "Valor Pago", "Valor a Pagar", "Valor de Juros"):
    df[field] = pd.to_numeric(df[field], errors="coerce").fillna(0)
# Preserva a classificação legada e usa as faixas do CIS onde não há classificação.
faixas = pd.cut(df["Quantia Creditada"], [-float("inf"), 5000, 25000, 100000, float("inf")],
               labels=["Até 5 000", "5 001 - 25 000", "25 001 - 100 000", "Acima de 100 000"]).astype("string")
if "Intervalo" in df:
    df["Intervalo"] = df["Intervalo"].astype("string").str.strip().replace("", pd.NA).fillna(faixas)
else:
    df["Intervalo"] = faixas

for field in ("Estado", "Estado do Contrato", "M. Pagamento", "Prazo/Duração", "Nome do Cliente"):
    df[field] = df[field].fillna("Não indicado")

# In[ ]:


def calcular_media_taxas_retencao(df):
    # Extrair os meses da coluna 'Data de Inicio'
    df['Mes'] = df['Data de Inicio'].dt.month
    df['Ano'] = df['Data de Inicio'].dt.year

    # Encontrar os meses únicos ordenados
    meses_unicos = df[['Ano', 'Mes']].drop_duplicates().sort_values(['Ano', 'Mes'])

    # Inicializar uma lista para armazenar as taxas de retenção
    taxas_de_retencao = []
    taxas_de_aquisição = []

    # Calcular a taxa de retenção sequencial entre os meses
    for i in range(1, len(meses_unicos)):
        mes_atual = meses_unicos.iloc[i]
        mes_anterior = meses_unicos.iloc[i - 1]
        
        # Filtrar dados para o mês atual e anterior
        filtered_dates_atual = df[(df['Ano'] == mes_atual['Ano']) & (df['Mes'] == mes_atual['Mes'])]
        filtered_dates_anterior = df[(df['Ano'] == mes_anterior['Ano']) & (df['Mes'] == mes_anterior['Mes'])]

        # Calcular a taxa de retenção entre os meses
        no_duplicates_atual = filtered_dates_atual["Nome do Cliente"]
        no_duplicates_anterior = filtered_dates_anterior["Nome do Cliente"]
        
        common_values = set(no_duplicates_atual) & set(no_duplicates_anterior)
        
        common_values1 = len(common_values)
        
        unique_values_filtered_dates_anterior = len(set(no_duplicates_atual) - common_values)

        nr_actual = len(no_duplicates_atual.drop_duplicates())
        
        nr_anterior = len(no_duplicates_anterior.drop_duplicates())
        
        if nr_anterior == 0:
            t_ret = 0
        else:
            t_ret = common_values1 / nr_anterior * 100
            
        if nr_actual == 0:
            t_aqu = 0
        else:
            t_aqu = unique_values_filtered_dates_anterior / nr_actual * 100
            
        taxas_de_retencao.append(t_ret)
        taxas_de_aquisição.append(t_aqu)
        
    # Calcular a média das taxas de retenção
    media_taxas_de_retencao = sum(taxas_de_retencao) / len(taxas_de_retencao) if taxas_de_retencao else 0
    media_taxas_de_aquisição = sum(taxas_de_aquisição) / len(taxas_de_aquisição) if taxas_de_aquisição else 0
    

    return media_taxas_de_retencao , media_taxas_de_aquisição

# Chamando a função com o DataFrame df2


# 

# In[1]:


def calcular_crecimento(df, ano, mes, target_year, mn):
    
    if ano == "Todos" and mes == "Todos":
            
        df2 = df[(df['Data de Inicio'].dt.month <= target_month)]
            
    elif ano == "Todos":
            # Apply conditions if only 'ano' is "Todos"
        df2 = df[(df['Data de Inicio'].dt.year <= target_year) & 
                                (df['Data de Inicio'].dt.month <= target_month)]
            
    elif mes == "Todos":
            # Apply conditions if only 'mes' is "Todos"
        df2 = df[(df['Data de Inicio'].dt.year == target_year) & 
                                (df['Data de Inicio'].dt.month <= target_month)]
            
    else:
            # Apply conditions if both 'ano' and 'mes' are specific values
        df2 = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month >= target_month - 1) & 
                            (df['Data de Inicio'].dt.month <= target_month)]

    # Extrair os meses da coluna 'Data de Inicio'
    df2['Mes'] = df2['Data de Inicio'].dt.month
    df2['Ano'] = df2['Data de Inicio'].dt.year


    # Encontrar os meses únicos ordenados
    meses_unicos = df2[['Ano', 'Mes']].drop_duplicates().sort_values(['Ano', 'Mes'])
    
    # Inicializar uma lista para armazenar as taxas de retenção

    taxas_de_crescimento= []
    taxas_de_crescimento1= []
    mês= []
    ano= []
    merged_list= []
    # Calcular a taxa de retenção sequencial entre os meses
    
    k = 1
    for i in range(1, len(meses_unicos)):
        mes_atual = meses_unicos.iloc[i]
        mes_anterior = meses_unicos.iloc[i - 1]
        
        # Filtrar dados para o mês atual e anterior
        filtered_dates_atual = df2[(df2['Ano'] == mes_atual['Ano']) & (df2['Mes'] == mes_atual['Mes'])]
        filtered_dates_anterior = df2[(df2['Ano'] == mes_anterior['Ano']) & (df2['Mes'] == mes_anterior['Mes'])]
        no_duplicates_atual = filtered_dates_atual["Quantia Creditada"].sum()
        no_duplicates_anterior = filtered_dates_anterior["Quantia Creditada"].sum()
        
        
        if (k == 1) :
            mês_atual = filtered_dates_atual["Data de Inicio"].dt.strftime('%b').drop_duplicates().tolist()
            ano_atual = filtered_dates_atual["Data de Inicio"].dt.year.drop_duplicates().tolist()
            mês_anterior = filtered_dates_anterior["Data de Inicio"].dt.strftime('%b').drop_duplicates().tolist()
            ano_anterior = filtered_dates_anterior["Data de Inicio"].dt.year.drop_duplicates().tolist()
            ki = filtered_dates_anterior["Data de Inicio"].dt.month.drop_duplicates()
            ki = ki.iloc[0]
                # Append the extracted values to the existing lists
            
            mês.extend(mês_anterior)
            ano.extend(ano_anterior)
            
            if (ki != mn):
                df1 = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month >= ki - 1) & 
                            (df['Data de Inicio'].dt.month <= ki)]
        
                df1['Mes'] = df1['Data de Inicio'].dt.month
                df1['Ano'] = df1['Data de Inicio'].dt.year
                meses_unicos1 = df1[['Ano', 'Mes']].drop_duplicates().sort_values(['Ano', 'Mes'])
                
                mes_atual1 = meses_unicos1.iloc[0]
                mes_anterior1 = meses_unicos1.iloc[0]
                filtered_dates_atual1 = df1[(df1['Ano'] == mes_atual1['Ano']) & (df1['Mes'] == mes_atual1['Mes'])]
                filtered_dates_anterior1 = df1[(df1['Ano'] == mes_anterior1['Ano']) & (df1['Mes'] == mes_anterior1['Mes'])]
                no_duplicates_atual1 = filtered_dates_atual1["Quantia Creditada"].sum()
                no_duplicates_anterior1 = filtered_dates_anterior1["Quantia Creditada"].sum()
                
                if (no_duplicates_anterior1 == 0):
                    t = 0
                else:
                    t = (no_duplicates_atual1 - no_duplicates_anterior1)/no_duplicates_anterior1*100
                
                taxas_de_crescimento1.append(t)
            else:
                t = 0
                taxas_de_crescimento1.append(t)
        
        # Calcular a taxa de retenção entre os meses
        mês_atual = filtered_dates_atual["Data de Inicio"].dt.strftime('%b').drop_duplicates().tolist()
        ano_atual = filtered_dates_atual["Data de Inicio"].dt.year.drop_duplicates().tolist()
        
        mês.extend(mês_atual)
        ano.extend(ano_atual)
        
        
        if (no_duplicates_anterior == 0):
            t = 0
        else:
            t = (no_duplicates_atual - no_duplicates_anterior)/no_duplicates_anterior*100
        
        taxas_de_crescimento.append(t)
        taxas_de_crescimento1.append(t)
        
        k += 1
        
    # Calcular a média das taxas de retenção
    
    if (len(taxas_de_crescimento) == 0):
        media_taxas_crescimento = 0
    else:
        media_taxas_crescimento = sum(taxas_de_crescimento) / len(taxas_de_crescimento)
    
    ano1 = pd.DataFrame(ano)
    
    k = 1
    
    if (len(ano1.drop_duplicates()) == 1):
        for i in range(len(taxas_de_crescimento1)):
            # Convert the date string to a datetime object
            date_string = f"{ano[i]}-{mês[i]}-01"
            date = datetime.strptime(date_string, '%Y-%b-%d')

            
            # Create the dictionary entry
            
            
            entry = {
                    "Taxa de Crescimento": taxas_de_crescimento1[i],
                    "Mês": mês[i],
                    "Data": date  # Add the date to the dictionary
            }
            

            merged_list.append(entry)
                
            
        data = pd.DataFrame(merged_list)
        
        hh = 0
        
        if data.empty:
            plt.figure(figsize=(8, 6))
        else:
            hh = 1
            
            
            
            unique_amounts1 = data.sort_values(by='Data', ascending=True)

                # Add a unique identifier column based on 
            data_inicio = unique_amounts1["Mês"]    
            valor_juros = unique_amounts1["Taxa de Crescimento"]

            plt.figure(figsize=(8, 6))
            plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers
            for i in range(len(data_inicio)):
                formatted_value = '{:.2f}%'.format(valor_juros[i])
                plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')

            plt.xlabel('Mês')
            plt.ylabel('Taxa de Crescimento(%)')
            plt.title('Gráfico de taxa de crescimento')

            plt.grid(False)  # Add grid lines
            plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
            plt.tight_layout()  # Ensure labels fit within the figure
        
        
    else:
        for i in range(len(taxas_de_crescimento1)):
            
            date_string = f"{ano[i]}-{mês[i]}-01"
            # Convert the date string to a datetime object
            date = datetime.strptime(date_string, '%Y-%b-%d')
            
            # Create the dictionary entry
            entry = {
                "Taxa de Crescimento": taxas_de_crescimento1[i],
                "Mês": mês[i],
                "Ano": ano[i],
                "Data": date  # Add the date to the dictionary
            }
            
            merged_list.append(entry)
            
        data = pd.DataFrame(merged_list)
        
        hh = 0
        
        if data.empty:
            plt.figure(figsize=(8, 6))
        else:
            hh = 1
            unique_amounts1 = data.sort_values(by='Data', ascending=True)
            
            # Combine "Ano" and "Meses" into a single label
            unique_amounts1["Ano-Meses"] = unique_amounts1.apply(lambda row: f"{row['Mês']}-{row['Ano']}", axis=1)

            data_inicio = unique_amounts1["Ano-Meses"]
            valor_juros = unique_amounts1["Taxa de Crescimento"]

            plt.figure(figsize=(8, 6))
            plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers

            for i in range(len(data_inicio)):
                    formatted_value = '{:.2f}%'.format(valor_juros[i])
                    plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')

            plt.xlabel('Ano-Meses')
            plt.ylabel('Taxa de Crescimento(%)')
            plt.title('Gráfico de taxa de crescimento')

            plt.grid(False)  # Add grid lines
            plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
            plt.tight_layout()  # Ensure labels fit within the figure
            
    
    return media_taxas_crescimento, plt, hh

# Chamando a função com o DataFrame df2


# In[2]:


def filtro(df):
    current_month = datetime.now(ZoneInfo("Africa/Maputo")).strftime('%b')
    current_year = datetime.now(ZoneInfo("Africa/Maputo")).year
    df['Data de Inicio'] = pd.to_datetime(df['Data de Inicio'], errors='coerce')
    df['Data de Cobrança'] = pd.to_datetime(df['Data de Cobrança'], errors='coerce')
    mes_options = ["Todos"] + df['Data de Inicio'].dt.strftime('%b').dropna().unique().tolist()
    ano_options = ["Todos"] + df['Data de Inicio'].dt.strftime('%Y').dropna().unique().tolist()
    
    mes_default_index = mes_options.index(current_month) if current_month in mes_options else 0
    ano_default_index = ano_options.index(str(current_year)) if str(current_year) in ano_options else 0
    
    ano = st.sidebar.selectbox("Seleccione o Ano:", 
                               options=ano_options,
                               index=ano_default_index
                              )
    mes = st.sidebar.selectbox("Seleccione o Mês:", 
                               options=mes_options,
                               index=mes_default_index
                              )
    
    # Conversion of month name to month number
    month_name_to_number = {
        "Jan": 1, "Feb": 2, "Mar": 3, "Apr": 4, "May": 5, "Jun": 6,
        "Jul": 7, "Aug": 8, "Sep": 9, "Oct": 10, "Nov": 11, "Dec": 12
    }
    
    if ano == "Todos":
        target_year = datetime.now(ZoneInfo("Africa/Maputo")).year
    else:
        target_year = int(ano)
    
    if mes == "Todos":
        latest_month_number = df['Data de Cobrança'].dt.month.max()
        target_month = int(latest_month_number) if pd.notna(latest_month_number) else datetime.now(ZoneInfo("Africa/Maputo")).month
    else:
        target_month = month_name_to_number[mes]
        
    
    p_pag = st.sidebar.multiselect("Seleccione o Prazo de Pagamento:", 
                                   options=df['Prazo/Duração'].unique(),
                                   default=df['Prazo/Duração'].unique()
                                  )
    m_pag = st.sidebar.multiselect("Seleccione a Modalidade de Pagamento:", 
                                   options=df['M. Pagamento'].unique(),
                                   default=df['M. Pagamento'].unique()
                                  )
    clientes = st.sidebar.multiselect("Seleccione o Cliente:", 
                                      options=df["Nome do Cliente"].unique(),
                                      default=df["Nome do Cliente"].unique()
                                     )
    
    k = 12 - target_month
    
    filtered_df = df[
        (df['Prazo/Duração'].isin(p_pag)) &
        (df['M. Pagamento'].isin(m_pag)) &
        (df["Nome do Cliente"].isin(clientes))
    ]
    
    filtered_df2 = df[
        (df['Data de Inicio'].dt.year <= target_year ) &
        (df['Data de Inicio'].dt.month <= target_month) &
        (df['Prazo/Duração'].isin(p_pag)) &
        (df['M. Pagamento'].isin(m_pag)) &
        (df["Nome do Cliente"].isin(clientes))
    ]
    
    if ano == "Todos":
        min_m = filtered_df2['Data de Inicio'].dt.month.min()
        ano_m = filtered_df2['Data de Inicio'].dt.year.min()
    else:
        min_m = filtered_df2['Data de Inicio'].dt.month.min()
        ano_m = target_year
    
    return ano, mes, target_year, target_month, filtered_df, filtered_df2, min_m, ano_m


# In[ ]:


menu = ["Desempenho do Portfólio de Microcrédito", "Análise de Clientes", "Desempenho Financeiro e Crescimento"]
choice = st.sidebar.selectbox("Escolha um Dashboard", menu)


# In[ ]:


if choice == "Desempenho do Portfólio de Microcrédito":
    # Conteúdo do Dashboard 1
    main_container = st.container()
    with main_container:
        st.title(":bar_chart: Desempenho do Portfólio de Microcrédito")
        st.markdown("##")
        ano , mes, target_year, target_month, df, df2, mn, an= filtro(df)
        if df2.empty:
            st.info("Sem contratos para os filtros seleccionados.")
            st.stop()
        # Meses Anteriores
        if ano == "Todos" and mes == "Todos":
            # Apply conditions if both 'ano' and 'mes' are "Todos"
            filtered_dates = df[(df['Data de Inicio'].dt.month < target_month) & 
                                (df['Estado do Contrato'] == "Em Progresso")]
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year <= target_year) & df2['Data de Cobrança'].dt.month <= target_month]

            if filtered_dates1.empty:
                # Get the latest date from df['Data de Cobrança']
                latest_date = df['Data de Cobrança'].max()

                # Set target_year and target_month based on the latest date
                target_year = latest_date.year
                target_month = latest_date.month

                # Filter the DataFrame using the updated target_year and target_month
                filtered_dates1 = df[(df['Data de Cobrança'].dt.year == target_year) & (df['Data de Cobrança'].dt.month <= target_month)]
            f = "Métricas Gerais"
            g = "Situações Pendentes"
            h = '%b'

        elif ano == "Todos":
            # Apply conditions if only 'ano' is "Todos"
            filtered_dates = df[(df['Data de Inicio'].dt.year <= target_year) & 
                                (df['Data de Inicio'].dt.month < target_month) & 
                                (df['Estado do Contrato'] == "Em Progresso")]
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year <= target_year) & df2['Data de Inicio'].dt.month <= target_month]
            f = "Métricas Gerais"
            g = "Situações Pendentes"
            h= '%b'
        elif mes == "Todos":
            # Apply conditions if only 'mes' is "Todos"
            filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                                (df['Data de Inicio'].dt.month < target_month) & 
                                (df['Estado do Contrato'] == "Em Progresso")]
            filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == target_year) & df2['Data de Cobrança'].dt.month <= target_month]

            if (filtered_dates1.empty) :
                # Get the latest date from df['Data de Cobrança']
                latest_date = df['Data de Cobrança'].max()
                # Set target_year and target_month based on the latest date
                target_year = latest_date.year
                target_month = latest_date.month

                # Filter the DataFrame using the updated target_year and target_month
                filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year == target_year) & (df2['Data de Cobrança'].dt.month <= target_month)] 
            f = f"Métricas Gerais do Ano de {ano}"
            g = "Situações Pendentes"
            h= '%b'
        else:
            # Apply conditions if both 'ano' and 'mes' are specific values
            filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                                (df['Data de Inicio'].dt.month < target_month) & 
                                (df['Estado do Contrato'] == "Em Progresso")]
            filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == target_year) & (df2['Data de Inicio'].dt.month == target_month)]
            f = f"Métricas do Mês de {mes} de {ano}"
            g = "Métricas do Meses Anteriores"
            h='%d-%b'


        non_duplicate_contratos = filtered_dates['Nr do Contracto'].drop_duplicates()
        
        
        filtered_dates = df[
        (df['Nr do Contracto'].isin(non_duplicate_contratos)) & 
        (~df['Estado'].str.contains('Pago')) 
        ]
        
        n_cativos1 = len(filtered_dates)

        n_ativos1 = len(non_duplicate_contratos)

        overall_sum1= filtered_dates['Valor a Pagar'].sum()


        filtered_dates2 = df[(df['Data de Inicio'].dt.month < target_month) & ((df['Estado'] == "Pago em Atraso") | (df['Estado'] == "Pagamento Atrasado")) ]

        total_occurrences = len(df[(df['Data de Inicio'].dt.month < target_month)])
        pago_occurrences = len(filtered_dates2)

        if total_occurrences != 0:
            ratio_pago1 = round((pago_occurrences / total_occurrences) * 100, 2)
        else:
            ratio_pago1 = 0

        # Mês Actual
        
        n_ativos = len(filtered_dates1[filtered_dates1['Estado'] == "Pendente"])

        n_ativoss = len(filtered_dates1)

        n_cativoss = len(filtered_dates1.drop_duplicates(subset=['Nr do Contracto', 'Estado do Contrato']))

        n_cativos = len(filtered_dates1[filtered_dates1['Estado do Contrato'] == "Em Progresso"].drop_duplicates(subset=['Nr do Contracto', 'Estado do Contrato']))

        filtered_dates11 = filtered_dates1.drop_duplicates(subset="Nr do Contracto")

        unique_amounts = (
            filtered_dates11.groupby(by=["Nr do Contracto"])["Quantia Creditada"].sum().reset_index().sort_values(by="Quantia Creditada")
        )

        overall_sum = unique_amounts['Quantia Creditada'].sum()
        avera_sum = unique_amounts['Quantia Creditada'].mean()

        total_occurrences = len(filtered_dates1)
    
        pago_occurrences = len(df2[(df2['Data de Inicio'].dt.month == target_month) & ((df2['Estado'] == "Pago em Atraso") | (df2['Estado'] == "Pagamento Atrasado"))])

        
        if total_occurrences != 0:
            ratio_pago = round((pago_occurrences / total_occurrences) * 100, 2)
        else:
            ratio_pago = 0

        filtered_dates1['Ordem'] = filtered_dates1['Intervalo'].str.split(' - ').str[0]

        unique_amounts = (
            filtered_dates1.groupby(by=["Intervalo","Ordem"])["Quantia Creditada"].sum().reset_index().sort_values(by="Ordem", ascending=True)
        )

        unique_amounts = unique_amounts.sort_values(by="Ordem", ascending=True)

        fig = px.pie(names=unique_amounts["Intervalo"], values=unique_amounts["Quantia Creditada"], title='Distribuição Faixa de Crédito')

        fig.update_traces(showlegend=True)

        font_size = "20px"
        if n_ativos1 != 0:
            st.markdown("####")
            st.write(f'<span style="font-size: {font_size};">:arrow_left: :chart_with_upwards_trend: {g}:</span>', unsafe_allow_html=True)
            # Define custom CSS style for the first Markdown (black color and bold)
            subheader_style = "font-size: 16px; color: black; font-weight: bold;text-align: center;"  # First style

            # Define custom CSS style for the second Markdown (customize as needed)
            second_style = "font-size: 14px; color: black; text-align: center;"  # Second style (customize as needed)


            # Create columns
            b1, b2, b3, b4 = st.columns(4)

            # Markdown for "Nº. Contratos Pendentes"
            with b1:
                st.markdown("<p style='{}'>Empréstimos Pendente:</p>".format(subheader_style), unsafe_allow_html=True)
                st.markdown(f"<p style='{second_style}'>{n_cativos1}</p>", unsafe_allow_html=True)

            # Markdown for "Nº. Parcelas Pendentes"
            with b2:
                st.markdown("<p style='{}'>Parcelas Pendente:</p>".format(subheader_style), unsafe_allow_html=True)
                st.markdown(f"<p style='{second_style}'>{n_ativos1}</p>", unsafe_allow_html=True)

            # Markdown for "Pagamento Esperado"
            with b3:
                st.markdown("<p style='{}'>Pagamento Esperado:</p>".format(subheader_style), unsafe_allow_html=True)
                st.markdown(f"<p style='{second_style}'>MZN {overall_sum1:,.2f}</p>", unsafe_allow_html=True)

            # Markdown for "Taxa de Inadimplência"
            with b4:
                st.markdown("<p style='{}'>Taxa de Inadimplência:</p>".format(subheader_style), unsafe_allow_html=True)
                st.markdown(f"<p style='{second_style}'>{ratio_pago1} %</p>", unsafe_allow_html=True)


        font_size = "20px"
        st.markdown("####")
        st.write(f'<span style="font-size: {font_size};">:clock1: :chart_with_upwards_trend: {f}</span>', unsafe_allow_html=True)

        padding = "1rem"  # Adjust the padding value as needed

        subheader_style = "font-size: 16px; color: black; font-weight: bold;text-align: center;"  # First style

            # Define custom CSS style for the second Markdown (customize as needed)
        second_style = "font-size: 14px; color: black; text-align: center;"  # Second style (customize as needed)
      # Adjust the padding value as needed
        # Create columns
        a1, a5, a2, a3, a4 = st.columns(5)

        # Markdown for "Volume de Empréstimos"
        with a1:
            st.markdown(
                f'<p style="{subheader_style}">Vol. de Empréstimos:</p>',
                unsafe_allow_html=True,
            )
            if (n_cativos == 0):
                st.markdown(
                    f'<p style="{second_style}">T: {n_cativoss}</p>',
                    unsafe_allow_html=True,
                )
            elif (n_cativoss == n_cativos):
                st.markdown(
                    f'<p style="{second_style}">A: {n_ativos}</p>',
                    unsafe_allow_html=True,
                )
            else:
                st.markdown(
                    f'<p style="{second_style}">T: {n_cativoss}</p>'
                    f'<p style="{second_style}">A: {n_ativos}</p>',
                    unsafe_allow_html=True,
                )

        # Separator between columns


        # Markdown for "Parcelas Contratuais"
        with a5:
            st.markdown(
                f'<p style="{subheader_style}">Parcelas Contratuais:</p>',
                unsafe_allow_html=True,
            )
            if (n_ativos == 0):
                st.markdown(
                    f'<p style="{second_style}">T: {n_ativoss}</p>',
                    unsafe_allow_html=True,
                )
            elif (n_ativoss == n_ativos):
                st.markdown(
                    f'<p style="{second_style}">A:{n_cativos}</p>',
                    unsafe_allow_html=True,
                )
            else:
                st.markdown(
                    f'<p style="{second_style}">T: {n_ativoss}</p>'
                    f'<p style="{second_style}">A: {n_cativos}</p>',
                    unsafe_allow_html=True,
                )

        # Separator between columns
        # Markdown for "M.T. em Empréstimos"
        with a2:
            st.markdown(f'<p style="{subheader_style}">V. em Empréstimos:</p>', unsafe_allow_html=True)
            st.markdown(f'<p style="{second_style}">MZN {overall_sum:,.2f}</p>', unsafe_allow_html=True)

        # Separator between columns


        # Markdown for "V.M. do Empréstimo"
        with a3:
            st.markdown(f'<p style="{subheader_style}">M. de Empréstimos:</p>', unsafe_allow_html=True)
            st.markdown(f'<p style="{second_style}">MZN {round(avera_sum, 2)}</p>', unsafe_allow_html=True)

        # Separator between columns

        # Markdown for "T. de Inadimplência"
        with a4:
            st.markdown(f'<p style="{subheader_style}">T. de Inadimplência:</p>', unsafe_allow_html=True)
            st.markdown(f'<p style="{second_style}">{ratio_pago:,.2f} %</p>', unsafe_allow_html=True)

        st.markdown("####")


        filtered_dates1["Meses"] = filtered_dates1["Data de Inicio"].dt.strftime('%b')  # Use '%b' for abbreviated month name
        filtered_dates1["Ano"] = filtered_dates1["Data de Inicio"].dt.strftime("%Y")

        l= len(filtered_dates1["Ano"].drop_duplicates())
        m = len(filtered_dates1["Meses"].drop_duplicates())

        filtered_dates1 = filtered_dates1.sort_values(by="Data de Inicio")

        filtered_dates11_no_duplicates = filtered_dates11.drop_duplicates(subset="Nr do Contracto")

        # Count the number of repeated items for each category in "Intervalo" column
        repeated_intervals_count = filtered_dates11_no_duplicates["Intervalo"].value_counts().reset_index()
        repeated_intervals_count.columns = ["Intervalo", "Count"]  

        fig_emprestimos2 = px.bar(
                repeated_intervals_count,
                x= "Count",
                y="Intervalo",
                orientation="h",
                title="<b>Gráfico de Barras de Intervalos</b>",
                color='Intervalo',  # Use the unique identifier for coloring bars
                color_continuous_scale='viridis',  # You can change the color scale
                template="plotly_white",
        )

        fig_emprestimos2.update_layout(
                    xaxis_title="Número"
            )


        if l == 1:
            if m == 1:

                unique_amounts1 = (
                    filtered_dates11.groupby(by=["Nr do Contracto","Data de Inicio"])["Quantia Creditada"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Data de Inicio", ascending=True)
                )  

                unique_amounts2 = (
                    filtered_dates11.groupby(by=["Nr do Contracto","Data de Inicio"])["Valor de Juros"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Data de Inicio", ascending=True)
                )



                fig_emprestimos = px.bar(
                    unique_amounts1,
                    x=unique_amounts1["Data de Inicio"].dt.strftime('%d-%b'),
                    y=unique_amounts1["Quantia Creditada"],
                    title="<b>Gráfico de Barras de Empréstimos</b>",
                    color='Nr do Contracto',  # Use the unique identifier for coloring bars
                    color_continuous_scale='viridis',  # You can change the color scale
                    template="plotly_white",
                )

                fig_emprestimos1 = px.bar(
                    unique_amounts2,
                    x=unique_amounts2["Data de Inicio"].dt.strftime('%d-%b'),
                    y=unique_amounts2["Valor de Juros"],
                    title="<b>Gráfico de Barras de Juros</b>",
                    color='Nr do Contracto',  # Use the unique identifier for coloring bars
                    color_continuous_scale='viridis',  # You can change the color scale
                    template="plotly_white",
                )

                fig_emprestimos.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Dia"
                )

                fig_emprestimos1.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Dia"
                )

            else:

                unique_amounts1 = (
                    filtered_dates1.groupby(by=["Meses"])["Quantia Creditada"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Quantia Creditada", ascending=False)
                )

                unique_amounts2 = (
                    filtered_dates1.groupby(by=["Meses"])["Valor de Juros"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Valor de Juros", ascending=False)
                )

                # Add a unique identifier column based on the dat


                fig_emprestimos = px.bar(
                    unique_amounts1,
                    x=unique_amounts1["Meses"],
                    y=unique_amounts1["Quantia Creditada"],
                    title="<b>Gráfico de Barras de Empréstimos</b>",
                    color='Meses',  # Use the unique identifier for coloring bars
                    color_continuous_scale='viridis',  # You can change the color scale
                    template="plotly_white",
                )

                fig_emprestimos1 = px.bar(
                    unique_amounts2,
                    x=unique_amounts2["Meses"],
                    y=unique_amounts2["Valor de Juros"],
                    title="<b>Gráfico de Barras de Juros</b>",
                    color='Meses',  # Use the unique identifier for coloring bars
                    color_continuous_scale='viridis',  # You can change the color scale
                    template="plotly_white",
                )

                fig_emprestimos.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Mês"
                )

                fig_emprestimos1.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Mês"
                )
        else:

            unique_amounts1 = (
                filtered_dates1.groupby(by=["Ano", "Meses"])["Quantia Creditada"]
                .sum()
                .reset_index()
                .sort_values(by="Quantia Creditada", ascending=True)
            )

            unique_amounts2 = (
                filtered_dates1.groupby(by=["Ano", "Meses"])["Valor de Juros"]
                .sum()
                .reset_index()
                .sort_values(by="Valor de Juros", ascending=True)
            )

            # Combine "Ano" and "Meses" into a single label
            unique_amounts1["Ano-Meses"] = unique_amounts1["Meses"] + "<br>" + unique_amounts1["Ano"]

            unique_amounts2["Ano-Meses"] = unique_amounts2["Meses"] + "<br>" + unique_amounts2["Ano"]

            fig_emprestimos = px.bar(
                unique_amounts1,
                x="Ano-Meses",  # Use the combined label for x-axis
                y="Quantia Creditada",
                title="<b>Gráfico de Barras de Empréstimos</b>",
                color='Ano-Meses',  # Use the unique identifier for coloring bars
                color_continuous_scale='viridis',  # You can change the color scale
                template="plotly_white",
            )


            fig_emprestimos1 = px.bar(
                unique_amounts2,
                x="Ano-Meses",  # Use the combined label for x-axis
                y="Valor de Juros",
                title="<b>Gráfico de Barras de Empréstimos</b>",
                color='Ano-Meses',  # Use the unique identifier for coloring bars
                color_continuous_scale='viridis',  # You can change the color scale
                template="plotly_white",
            )

            fig_emprestimos.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Mês-Ano"
            )

            fig_emprestimos1.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat="%d-%b"
                    ),
                    yaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    ),
                    xaxis_title="Mês-Ano"
            )
        st.sidebar.write("")
        # Center the chart within the main content area


        # Display the chart in the container with the specified class

        # Render the chart within the centered container       


        chart_height = 500
        
        c1, c2= st.columns(2)

        with c1:
            st.plotly_chart(fig_emprestimos, width='stretch')
        with c2:
            st.plotly_chart(fig_emprestimos1, width='stretch')

        c0, c00= st.columns(2)
        with c0:
            st.plotly_chart(fig, width='stretch')
        with c00:
            st.plotly_chart(fig_emprestimos2, width='stretch')
    


# In[1]:


elif choice == "Análise de Clientes":
    # Conteúdo do Dashboard 2
    st.title(":bar_chart: Eficiência Operacional e Clientes")
    st.markdown("##")
    ano , mes, target_year, target_month, df, df2, mn, an= filtro(df)
    if df2.empty:
        st.info("Sem contratos para os filtros seleccionados.")
        st.stop()
    
    if ano == "Todos" and mes == "Todos":
        # Apply conditions if both 'ano' and 'mes' are "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.year == an) & 
                            (df['Data de Inicio'].dt.month == mn)]
        if mn == 12:
            filtered_dates1 = df2[(df2['Data de Inicio'].dt.year > an) & (df2['Data de Inicio'].dt.month > mn)]
        else:
            filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == an) & (df2['Data de Inicio'].dt.month > mn)]
            
        
        if filtered_dates1.empty:
            # Get the latest date from df['Data de Cobrança']
            latest_date = df['Data de Cobrança'].max()

            # Set target_year and target_month based on the latest date
            target_year = latest_date.year
            target_month = latest_date.month

            # Filter the DataFrame using the updated target_year and target_month
            filtered_dates1 = df[(df['Data de Cobrança'].dt.year == target_year) & (df['Data de Cobrança'].dt.month <= target_month)]
        f = "Métricas Gerais"
        g = "Situações Pendentes"
        h = '%b'
        
    elif ano == "Todos":
        # Apply conditions if only 'ano' is "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.year == an) & 
                            (df['Data de Inicio'].dt.month == mn)]
        if mn == 12:
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year > an) & (df2['Data de Inicio'].dt.month <= target_month) & (df2['Data de Inicio'].dt.month > mn)]
        else:
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year <= target_year) & (df2['Data de Inicio'].dt.month <= target_month) & (df2['Data de Inicio'].dt.month > mn)]
            filtered_dates2 = df2[(df2['Data de Inicio'].dt.year <= target_year) & (df2['Data de Inicio'].dt.month >= mn)]
        
        f = "Métricas Gerais"
        g = "Situações Pendentes"
        h= '%b'
    elif mes == "Todos":
        # Apply conditions if only 'mes' is "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month == mn)]
        filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == target_year) & (df2['Data de Inicio'].dt.month > mn)]
        filtered_dates2 = df2[(df2['Data de Inicio'].dt.year == target_year) & (df2['Data de Inicio'].dt.month >= mn)]
        if (filtered_dates1.empty) :
            # Get the latest date from df['Data de Cobrança']
            latest_date = df['Data de Cobrança'].max()
            # Set target_year and target_month based on the latest date
            target_year = latest_date.year
            target_month = latest_date.month

            # Filter the DataFrame using the updated target_year and target_month
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year == target_year) & (df2['Data de Cobrança'].dt.month <= target_month)] 
        f = f"Métricas Gerais do Ano de {ano}"
        g = "Situações Pendentes"
        h= '%b'
    else:
        # Apply conditions if both 'ano' and 'mes' are specific values
        filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month == target_month - 1)]
        filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == target_year) & (df2['Data de Inicio'].dt.month == target_month)]
        f = f"Métricas do Mês de {mes} de {ano}"
        g = "Métricas do Meses Anteriores"
        h='%d-%b'
        
    
    
    if ano == "Todos" or mes == "Todos":
        filtered_dates2 = df2 if ano == "Todos" else df2[df2['Data de Inicio'].dt.year == target_year]
    x = int(ano == "Todos" or mes == "Todos")

    no_duplicates = filtered_dates["Nome do Cliente"]
    
    no_duplicates1 = filtered_dates1["Nome do Cliente"]
    

    common_values = set(no_duplicates) & set(no_duplicates1)
    
    common_values1 = len(common_values)
    
    unique_values_filtered_dates1 = len(set(no_duplicates1) - common_values)
    
    nr = len(filtered_dates["Nome do Cliente"].drop_duplicates())
    
    nr1 = len(filtered_dates1["Nome do Cliente"].drop_duplicates())
    
    
    
    
    if nr == 0:
        t_ret = 0
    else:
        if (mes == "Todos") | (ano == "Todos"):
            media_taxas, media_taxas1= calcular_media_taxas_retencao(filtered_dates2)
            t_ret = media_taxas

        else:
            t_ret = common_values1/nr * 100

    if nr1 == 0:
        t_aqu = 0
    else:
        if (mes == "Todos") | (ano == "Todos"):
            media_taxas, media_taxas1 = calcular_media_taxas_retencao(filtered_dates2)
            t_aqu = media_taxas1
            nr1 = len(filtered_dates2["Nome do Cliente"].drop_duplicates())

        else:
            t_aqu = unique_values_filtered_dates1 / nr1 * 100

    font_size = "20px"
    st.markdown("####")
    st.write(f'<span style="font-size: {font_size};">:clock1: :chart_with_upwards_trend: {f}</span>', unsafe_allow_html=True)
    
    padding = "1rem"  # Adjust the padding value as needed

    subheader_style = "font-size: 16px; color: black; font-weight: bold;text-align: center;"  # First style

        # Define custom CSS style for the second Markdown (customize as needed)
    second_style = "font-size: 14px; color: black; text-align: center;"  # Second style (customize as needed)
  # Adjust the padding value as needed
    # Create columns
    a1, a_sep1, a5, a_sep2, a2, a_sep3, a3, = st.columns([1, 0.1, 1, 0.1, 1, 0.1, 1])

    # Markdown for "Volume de Empréstimos"

    with a1:
        st.markdown(
            f'<p style="{subheader_style}">Clientes:</p>',
            unsafe_allow_html=True,
        )
        st.markdown(
            f'<p style="{second_style}">{nr1}</p>',
            unsafe_allow_html=True,
        )
        

    # Separator between columns
    with a_sep1:
        st.markdown('<div style="height: 100%; border-right: 2px solid #555;"></div>', unsafe_allow_html=True)

    # Markdown for "Parcelas Contratuais"
    with a5:
        st.markdown(
            f'<p style="{subheader_style}">Novos Clientes:</p>',
            unsafe_allow_html=True,
        )
        st.markdown(
            f'<p style="{second_style}">{unique_values_filtered_dates1}</p>',
            unsafe_allow_html=True,
        )

        
    # Separator between columns
    with a_sep2:
        st.markdown('<div style="height: 100%; border-right: 2px solid #555;"></div>', unsafe_allow_html=True)

    # Markdown for "M.T. em Empréstimos"
    with a2:
        st.markdown(f'<p style="{subheader_style}">Taxa de Retenção:</p>', unsafe_allow_html=True)
        st.markdown(
            f'<p style="{second_style}">{t_ret:,.2f}%</p>',
            unsafe_allow_html=True,
        )

    # Separator between columns
    with a_sep3:
        st.markdown('<div style="height: 100%; border-right: 2px solid #555;"></div>', unsafe_allow_html=True)

    # Markdown for "V.M. do Empréstimo"
    with a3:
        st.markdown(f'<p style="{subheader_style}">Taxa Aquisição:</p>', unsafe_allow_html=True)
        st.markdown(
            f'<p style="{second_style}">{t_aqu:,.2f}%</p>',
            unsafe_allow_html=True,
        )


    # Separator between columns

    st.markdown("####")
    
    
    if (x==1):
        filtered_dates1 = filtered_dates2
    
    unique_amounts1 = (
                    filtered_dates1.groupby(by=["Nome do Cliente"])["Quantia Creditada"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Quantia Creditada", ascending=False)
                ) 


    fig_emprestimos = px.bar(
                    unique_amounts1,
                    x=unique_amounts1["Quantia Creditada"],
                    y=unique_amounts1["Nome do Cliente"],
                    orientation="h",
                    title="<b>Gráfico de Clientes (Crédito)</b>",
                    color='Nome do Cliente',  # Use the unique identifier for coloring bars
                    color_continuous_scale='viridis',  # You can change the color scale
                    template="plotly_white",
                )
    
    fig_emprestimos.update_layout(
                    plot_bgcolor="rgba(0,0,0,0)",
                    xaxis=dict(
                        showgrid=False,
                        tickformat=",.2f"
                    )
            )
    
    filtered_dates11_no_duplicates = filtered_dates1.drop_duplicates(subset="Nr do Contracto")
    
        # Count the number of repeated items for each category in "Intervalo" column
    repeated_intervals_count = filtered_dates11_no_duplicates["Nome do Cliente"].value_counts().reset_index()
    repeated_intervals_count.columns = ["Nome do Cliente", "Count"]  

    fig_emprestimos2 = px.bar(
                repeated_intervals_count,
                x= "Count",
                y="Nome do Cliente",
                orientation="h",
                title="<b>Gráfico de Clientes (Frequência)</b>",
                color='Nome do Cliente',  # Use the unique identifier for coloring bars
                color_continuous_scale='viridis',  # You can change the color scale
                template="plotly_white",
        )

    fig_emprestimos2.update_layout(
                    xaxis_title="Clientes"
            )
    
    
    
    filtered3 = filtered_dates1.drop_duplicates(subset="Nome do Cliente")
    
    merged_df = pd.merge(filtered3, df3[['Nome Completo', 'Estado Civil']], left_on='Nome do Cliente', right_on='Nome Completo', how='left')

    # Drop the redundant "Nome Completo" column
    merged_df.drop('Nome Completo', axis=1, inplace=True)

    # Rename the "Estado Civil" column to match the desired column name
    merged_df.rename(columns={'Estado Civil': 'Estado Civil'}, inplace=True)

    # Update the original filtered3 DataFrame with the merged data
    filtered3 = merged_df
    
    repeated_intervals_count = filtered3["Estado Civil"].value_counts().reset_index()
    repeated_intervals_count.columns = ["Estado Civil", "Count"]
    
    fig = px.pie(names=repeated_intervals_count["Estado Civil"], values=repeated_intervals_count["Count"], title='Distribuição do Estado Civil')

    fig.update_traces(showlegend=True)
    
    
    
    chart_height = 500
    
    a1, a2  =  st.columns(2)
    with a1:
        st.plotly_chart(fig_emprestimos, width='stretch')
    with a2:
        st.plotly_chart(fig_emprestimos2, width='stretch')
        
        
    st.plotly_chart(fig, width='stretch')
    


# In[ ]:


elif choice == "Desempenho Financeiro e Crescimento":
    # Conteúdo do Dashboard 3
    st.title(":bar_chart: Desempenho Financeiro e Crescimento")
    st.markdown("##")
    ano , mes, target_year, target_month, df, df2, mn, an= filtro(df)
    if df2.empty:
        st.info("Sem contratos para os filtros seleccionados.")
        st.stop()
    
    if ano == "Todos" and mes == "Todos":
        # Apply conditions if both 'ano' and 'mes' are "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.month < target_month) & 
                            (df['Estado do Contrato'] == "Em Progresso")]
        filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year <= target_year) & df2['Data de Cobrança'].dt.month <= target_month]
        
        if filtered_dates1.empty:
            # Get the latest date from df['Data de Cobrança']
            latest_date = df['Data de Cobrança'].max()

            # Set target_year and target_month based on the latest date
            target_year = latest_date.year
            target_month = latest_date.month

            # Filter the DataFrame using the updated target_year and target_month
            filtered_dates1 = df[(df['Data de Cobrança'].dt.year == target_year) & (df['Data de Cobrança'].dt.month <= target_month)]
        f = "Métricas Gerais"
        g = "Situações Pendentes"
        h = '%b'
        
    elif ano == "Todos":
        # Apply conditions if only 'ano' is "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.year <= target_year) & 
                            (df['Data de Inicio'].dt.month < target_month) & 
                            (df['Estado do Contrato'] == "Em Progresso")]
        filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year <= target_year) & df2['Data de Inicio'].dt.month <= target_month]
        f = "Métricas Gerais"
        g = "Situações Pendentes"
        h= '%b'
    elif mes == "Todos":
        # Apply conditions if only 'mes' is "Todos"
        filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month < target_month) & 
                            (df['Estado do Contrato'] == "Em Progresso")]
                            
        filtered_dates1 = df2[
                            (df2['Data de Inicio'].dt.year == target_year) &
                            (df2['Data de Inicio'].dt.month <= target_month)]
        
        if (filtered_dates1.empty) :
            # Get the latest date from df['Data de Cobrança']
            latest_date = df['Data de Cobrança'].max()
            # Set target_year and target_month based on the latest date
            target_year = latest_date.year
            target_month = latest_date.month

            # Filter the DataFrame using the updated target_year and target_month
            filtered_dates1 = df2[(df2['Data de Cobrança'].dt.year == target_year) & (df2['Data de Cobrança'].dt.month <= target_month)] 
        f = f"Métricas Gerais do Ano de {ano}"
        g = "Situações Pendentes"
        h= '%b'
    else:
        # Apply conditions if both 'ano' and 'mes' are specific values
        filtered_dates = df[(df['Data de Inicio'].dt.year == target_year) & 
                            (df['Data de Inicio'].dt.month < target_month) & 
                            (df['Estado do Contrato'] == "Em Progresso")]
        filtered_dates1 = df2[(df2['Data de Inicio'].dt.year == target_year) & (df2['Data de Inicio'].dt.month == target_month)]
        f = f"Métricas do Mês de {mes} de {ano}"
        g = "Métricas do Meses Anteriores"
        h='%d-%b'
    
   
   
   
    p_pend1 =  filtered_dates[(filtered_dates['Estado'] == "Pendente") | (filtered_dates['Estado'] == "Pagamento Atrasado")]
    
    p_pend = p_pend1['Valor Pago'].sum()
    
    p_pend2 = p_pend1['Valor a Pagar'].sum() - p_pend1['Valor de Juros'].sum() 
    
    p_pend3 = p_pend1['Valor de Juros'].sum()
    
    p_pend4 = len(p_pend1['Valor Pago'])
    
    # Mês Actual

    
    act1 =  filtered_dates1[(filtered_dates1['Estado'] == "Pago") | (filtered_dates1['Estado'] == "Pago em Atraso")]
    
    act2 =  filtered_dates1[(filtered_dates1['Estado'] == "Pendente") | (filtered_dates1['Estado'] == "Pagamento Atrasado") ]
     
    p_efec =  act1['Valor Pago'].sum()
     
    n_efec = len(act1)
    
    p_pen = act2['Valor Pago'].sum()
    
    n_pen = len(act2)
    
    n_total = n_efec + n_pen
    
    p_efec =  act1['Valor Pago'].sum()
    
    p_cpen = act2['Valor a Pagar'].sum() - act2['Valor de Juros'].sum() 
    
    p_cefec = act1['Valor a Pagar'].sum() - act1['Valor de Juros'].sum() 
    
    p_jpen = act2['Valor de Juros'].sum() 
    
    p_jefec = act1['Valor de Juros'].sum() 
    
    g1 = filtered_dates1["Valor de Juros"].sum()
    
    h = filtered_dates1['Valor a Pagar'].sum() - filtered_dates1['Valor de Juros'].sum()
    
    taxa_j =  (filtered_dates1["Valor de Juros"].sum()/(filtered_dates1['Valor a Pagar'].sum() - filtered_dates1['Valor de Juros'].sum()))*100

    
    n_ativos = len(filtered_dates1[filtered_dates1['Estado do Contrato'] == "Em Progresso"])
    
    n_ativoss = len(filtered_dates1)
    
    n_cativoss = len(filtered_dates1.drop_duplicates(subset=['Nr do Contracto', 'Estado do Contrato']))
    
    n_cativos = len(filtered_dates1[filtered_dates1['Estado do Contrato'] == "Em Progresso"].drop_duplicates(subset=['Nr do Contracto', 'Estado do Contrato']))
    
    filtered_dates11 = filtered_dates1.drop_duplicates(subset="Nr do Contracto")
    
    unique_amounts = (
        filtered_dates11.groupby(by=["Nr do Contracto"])["Quantia Creditada"].sum().reset_index().sort_values(by="Quantia Creditada")
    )
    
    overall_sum = unique_amounts['Quantia Creditada'].sum()
    avera_sum = unique_amounts['Quantia Creditada'].mean()
    
    total_occurrences = len(filtered_dates1)
    pago_occurrences = len(df[(df['Data de Inicio'].dt.month == target_month) & ((df['Estado'] == "Pago em Atraso") | (df['Estado'] == "Pagamento Atrasado"))])
    
    
    if total_occurrences != 0:
        ratio_pago = round((pago_occurrences / total_occurrences) * 100, 2)
    else:
        ratio_pago = 0
        
    filtered_dates1['Ordem'] = filtered_dates1['Intervalo'].str.split(' - ').str[0]
    
    unique_amounts = (
        filtered_dates1.groupby(by=["Intervalo","Ordem"])["Quantia Creditada"].sum().reset_index().sort_values(by="Ordem", ascending=True)
    )
    
    unique_amounts = unique_amounts.sort_values(by="Ordem", ascending=True)
    
    fig = px.pie(names=unique_amounts["Intervalo"], values=unique_amounts["Quantia Creditada"], title='Distribuição Faixa de Crédito')
    
    fig.update_traces(showlegend=True)
    
    tc, plt1, h11 = calcular_crecimento(df2, ano, mes, target_year, mn)
    
    
    font_size = "20px"
    
    if p_pend4 != 0:
        st.markdown("####")
        st.write(f'<span style="font-size: {font_size};">:arrow_left: :chart_with_upwards_trend: {g}:</span>', unsafe_allow_html=True)
        # Define custom CSS style for the first Markdown (black color and bold)
        subheader_style = "font-size: 16px; color: black; font-weight: bold;text-align: center;"  # First style

        # Define custom CSS style for the second Markdown (customize as needed)
        second_style = "font-size: 14px; color: black; text-align: center;"  # Second style (customize as needed)

        # Create columns
        b1, b2, b3, b4 = st.columns(4)

        # Markdown for "Nº. Contratos Pendentes"
        with b1:
            st.markdown("<p style='{}'>Nº. P. Pendentes:</p>".format(subheader_style), unsafe_allow_html=True)
            st.markdown(f"<p style='{second_style}'>{p_pend4}</p>", unsafe_allow_html=True)

        # Markdown for "Nº. Parcelas Pendentes"
        with b2:
            st.markdown("<p style='{}'>Total a Pagar:</p>".format(subheader_style), unsafe_allow_html=True)
            st.markdown(f"<p style='{second_style}'>{p_pend:,.2f}</p>", unsafe_allow_html=True)

        # Markdown for "Pagamento Esperado"
        with b3:
            st.markdown("<p style='{}'>Valor do Crédito:</p>".format(subheader_style), unsafe_allow_html=True)
            st.markdown(f"<p style='{second_style}'>MZN {p_pend2:,.2f}</p>", unsafe_allow_html=True)

        # Markdown for "Taxa de Inadimplência"
        with b4:
            st.markdown("<p style='{}'>Valor do Juros:</p>".format(subheader_style), unsafe_allow_html=True)
            st.markdown(f"<p style='{second_style}'>MZN {p_pend3:,.2f} </p>", unsafe_allow_html=True)
            
    
    font_size = "20px"
    st.markdown("####")
    st.write(f'<span style="font-size: {font_size};">:clock1: :chart_with_upwards_trend: {f}</span>', unsafe_allow_html=True)
    
    padding = "1rem"  # Adjust the padding value as needed

    subheader_style = "font-size: 16px; color: black; font-weight: bold;text-align: center;"  # First style
    subheader_style1 = "font-size: 14px; color: black; font-weight: bold;text-align: center;"
        # Define custom CSS style for the second Markdown (customize as needed)
    second_style = "font-size: 14px; color: black; text-align: center;"  # Second style (customize as needed)
  # Adjust the padding value as needed
    # Create columns
    
     
    c1, a1, a5, a2, a3, a4 = st.columns([0.4,1, 1, 1, 1, 1])
    
    if (n_efec == 0):
     # Markdown for "Volume de Empréstimos"
        with c1:
            st.markdown(
                f'<p style="font-size: 16px; color: white; font-weight: bold; text-align: center;">ST:</p>',
                unsafe_allow_html=True,
            )

            st.markdown(
                f'<p style="{subheader_style1}">P: </p>',
                unsafe_allow_html=True,
                )       

        with a1:
            st.markdown(
                f'<p style="{subheader_style}">Nº de Pag:</p>',
                unsafe_allow_html=True,
            )
            st.markdown(
                f'<p style="{second_style}">{n_total}</p>',
                unsafe_allow_html=True,
            )



        # Markdown for "Parcelas Contratuais"
        with a5:
            st.markdown(
                f'<p style="{subheader_style}">V. a Pagar:</p>',
                unsafe_allow_html=True,
            )
            st.markdown(
                f'<p style="{second_style}">MZN {(p_efec+p_pen):,.2f}</p>',
                unsafe_allow_html=True,
            )




        # Markdown for "M.T. em Empréstimos"
        with a2:
            st.markdown(f'<p style="{subheader_style}">V. de Juros:</p>', unsafe_allow_html=True)
            st.markdown(
                f'<p style="{second_style}">MZN {(p_jefec+p_jpen):,.2f}</p>',
                unsafe_allow_html=True,
            )


        # Markdown for "V.M. do Empréstimo"
        with a3:
            st.markdown(f'<p style="{subheader_style}">T. de Juros Efetiva:</p>', unsafe_allow_html=True)
            st.markdown(f'<p style="{second_style}">{round(taxa_j, 2)} % </p>', unsafe_allow_html=True)


        # Markdown for "T. de Inadimplência"
        with a4:
            st.markdown(f'<p style="{subheader_style}">T. de Crescimento:</p>', unsafe_allow_html=True)
            st.markdown(f'<p style="{second_style}">{tc:,.2f} %</p>', unsafe_allow_html=True)

        st.markdown("####")   
    
    else:
        if(n_pen == 0):
            # Markdown for "Volume de Empréstimos"
            with c1:
                st.markdown(
                    f'<p style="font-size: 16px; color: white; font-weight: bold; text-align: center;">ST:</p>',
                    unsafe_allow_html=True,
                )

                st.markdown(
                    f'<p style="{subheader_style1}">E: </p>',
                    unsafe_allow_html=True,
                    )       

            with a1:
                st.markdown(
                    f'<p style="{subheader_style}">Nº de Pag:</p>',
                    unsafe_allow_html=True,
                )
                st.markdown(
                    f'<p style="{second_style}">{n_total}</p>',
                    unsafe_allow_html=True,
                )



            # Markdown for "Parcelas Contratuais"
            with a5:
                st.markdown(
                    f'<p style="{subheader_style}">V. a Pagar:</p>',
                    unsafe_allow_html=True,
                )
                st.markdown(
                    f'<p style="{second_style}">MZN {(p_efec+p_pen):,.2f}</p>',
                    unsafe_allow_html=True,
                )




            # Markdown for "M.T. em Empréstimos"
            with a2:
                st.markdown(f'<p style="{subheader_style}">V. de Juros:</p>', unsafe_allow_html=True)
                st.markdown(
                    f'<p style="{second_style}">MZN {(p_jefec+p_jpen):,.2f}</p>',
                    unsafe_allow_html=True,
                )


            # Markdown for "V.M. do Empréstimo"
            with a3:
                st.markdown(f'<p style="{subheader_style}">T. de Juros Efetiva:</p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}">{round(taxa_j, 2)} % </p>', unsafe_allow_html=True)


            # Markdown for "T. de Inadimplência"
            with a4:
                st.markdown(f'<p style="{subheader_style}">T. de Crescimento:</p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}">{tc:,.2f} %</p>', unsafe_allow_html=True)


            st.markdown("####")            
        else:
            # Markdown for "Volume de Empréstimos"
            with c1:
                st.markdown(
                    f'<p style="font-size: 16px; color: white; font-weight: bold; text-align: center;">ST:</p>',
                    unsafe_allow_html=True,
                )

                st.markdown(
                    f'<p style="{subheader_style1}">E: </p>'
                    f'<p style="{subheader_style1}">P: </p>'
                    f'<p style="{subheader_style1}">T: </p>',
                    unsafe_allow_html=True,
                    )       

            with a1:
                st.markdown(
                    f'<p style="{subheader_style}">Nº de Pag:</p>',
                    unsafe_allow_html=True,
                )
                st.markdown(
                    f'<p style="{second_style}">{n_efec}</p>'
                    f'<p style="{second_style}">{n_pen}</p>'
                    f'<p style="{second_style}">{n_total}</p>',
                    unsafe_allow_html=True,
                )



            # Markdown for "Parcelas Contratuais"
            with a5:
                st.markdown(
                    f'<p style="{subheader_style}">V. a Pagar:</p>',
                    unsafe_allow_html=True,
                )
                st.markdown(
                    f'<p style="{second_style}">MZN {p_efec:,.2f}</p>'
                    f'<p style="{second_style}">MZN {p_pen:,.2f}</p>'
                    f'<p style="{second_style}">MZN {(p_efec+p_pen):,.2f}</p>',
                    unsafe_allow_html=True,
                )




            # Markdown for "M.T. em Empréstimos"
            with a2:
                st.markdown(f'<p style="{subheader_style}">V. de Juros:</p>', unsafe_allow_html=True)
                st.markdown(
                    f'<p style="{second_style}">MZN {p_jefec:,.2f}</p>'
                    f'<p style="{second_style}">MZN {p_jpen:,.2f}</p>'
                    f'<p style="{second_style}">MZN {(p_jefec+p_jpen):,.2f}</p>',
                    unsafe_allow_html=True,
                )


            # Markdown for "V.M. do Empréstimo"
            with a3:
                st.markdown(f'<p style="{subheader_style}">T. de Juros Efetiva:</p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}"></p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}">{round(taxa_j, 2)} % </p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}"></p>', unsafe_allow_html=True)


            # Markdown for "T. de Inadimplência"
            with a4:
                st.markdown(f'<p style="{subheader_style}">T. de Crescimento:</p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}"></p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}">{tc:,.2f} %</p>', unsafe_allow_html=True)
                st.markdown(f'<p style="{second_style}"></p>', unsafe_allow_html=True)

            st.markdown("####")
    
    
    filtered_dates1["Meses"] = filtered_dates1["Data de Inicio"].dt.strftime('%b') 
    filtered_dates1["Nr"] = filtered_dates1["Data de Inicio"].dt.strftime('%m') # Use '%b' for abbreviated month name
    filtered_dates1["Ano"] = filtered_dates1["Data de Inicio"].dt.strftime("%Y")
    
    l= len(filtered_dates1["Ano"].drop_duplicates())
    m = len(filtered_dates1["Meses"].drop_duplicates())
    
    filtered_dates1 = filtered_dates1.sort_values(by="Data de Inicio")
  # Ensure labels fit within the figure 
        
    st.sidebar.write("")
    
    if (h11 == 1):
        c1, a1 = st.columns(2)

        # Markdown for "Volume de Empréstimos"
        with c1:
            st.pyplot(plt1) 
        with a1:
            plt1.close()
            if l == 1:
                if m == 1:

                    unique_amounts1 = (
                        filtered_dates11.groupby(by=["Data de Inicio"])["Valor de Juros"]
                        .sum()
                        .reset_index()
                        .sort_values(by="Data de Inicio", ascending=True)
                    )  


                    plt.figure(figsize=(8, 6))  # Set the figure size

                    data_inicio = unique_amounts1["Data de Inicio"].dt.strftime('%d-%b')
                    valor_juros = unique_amounts1["Valor de Juros"]
                    plt.plot(unique_amounts1["Data de Inicio"].dt.strftime('%d-%b'), unique_amounts1["Valor de Juros"], marker='o', linestyle='-')

                    for i in range(len(data_inicio)):
                        formatted_value = '{:.2f}'.format(valor_juros[i])
                        plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')
                      # Line plot with markers

                    plt.xlabel('Dia')
                    plt.ylabel('Valor de Juros')
                    plt.title('Gráfico de Linha de Lucro')

                    plt.grid(False)  # Add grid lines
                    plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
                    plt.tight_layout()  # Ensure labels fit within the figure
                    plt.show()

                else:

                    unique_amounts1 = (
                        filtered_dates1.groupby(by=["Meses","Nr"])["Valor de Juros"]
                        .sum()
                        .reset_index()
                        .sort_values(by="Nr", ascending=True)
                    )

                    # Add a unique identifier column based on the dat
                    data_inicio = unique_amounts1["Meses"]
                    valor_juros = unique_amounts1["Valor de Juros"]

                    plt.figure(figsize=(8, 6))  # Set the figure size
                    plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers
                    for i in range(len(data_inicio)):
                        formatted_value = '{:.2f}'.format(valor_juros[i])
                        plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')

                    plt.xlabel('Mês')
                    plt.ylabel('Valor de Juros')
                    plt.title('Gráfico de Linha de Lucro')

                    plt.grid(False)  # Add grid lines
                    plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
                    plt.tight_layout()  # Ensure labels fit within the figure
                    plt.show()

            else:

                unique_amounts1 = (
                    filtered_dates1.groupby(by=["Ano", "Meses"])["Valor de Juros"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Valor de Juros", ascending=True)
                )


                # Combine "Ano" and "Meses" into a single label
                unique_amounts1["Ano-Meses"] = unique_amounts1["Meses"] + "-" + unique_amounts1["Ano"]

                data_inicio = unique_amounts1["Ano-Meses"]
                valor_juros = unique_amounts1["Valor de Juros"]
                plt.figure(figsize=(8, 6))  # Set the figure size
                plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers

                for i in range(len(data_inicio)):
                        formatted_value = '{:.2f}'.format(valor_juros[i])
                        plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')
                plt.xlabel('Ano-Meses')
                plt.ylabel('Valor de Juros')
                plt.title('Gráfico de Linha de Lucro')

                plt.grid(False)  # Add grid lines
                plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
                plt.tight_layout()
                
            st.pyplot(plt)
    else:
        if l == 1:
            if m == 1:

                unique_amounts1 = (
                    filtered_dates11.groupby(by=["Data de Inicio"])["Valor de Juros"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Data de Inicio", ascending=True)
                )  


                plt.figure(figsize=(8, 6))  # Set the figure size

                data_inicio = unique_amounts1["Data de Inicio"].dt.strftime('%d-%b')
                valor_juros = unique_amounts1["Valor de Juros"]
                plt.plot(unique_amounts1["Data de Inicio"].dt.strftime('%d-%b'), unique_amounts1["Valor de Juros"], marker='o', linestyle='-')

                for i in range(len(data_inicio)):
                    formatted_value = '{:.2f}'.format(valor_juros[i])
                    plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')
                  # Line plot with markers

                plt.xlabel('Dia')
                plt.ylabel('Valor de Juros')
                plt.title('Gráfico de Linha de Lucro')

                plt.grid(False)  # Add grid lines
                plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
                plt.tight_layout()  # Ensure labels fit within the figure
                plt.show()

            else:

                unique_amounts1 = (
                    filtered_dates1.groupby(by=["Meses","Nr"])["Valor de Juros"]
                    .sum()
                    .reset_index()
                    .sort_values(by="Nr", ascending=True)
                )

                # Add a unique identifier column based on the dat
                data_inicio = unique_amounts1["Meses"]
                valor_juros = unique_amounts1["Valor de Juros"]

                plt.figure(figsize=(8, 6))  # Set the figure size
                plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers
                for i in range(len(data_inicio)):
                    formatted_value = '{:.2f}'.format(valor_juros[i])
                    plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')

                plt.xlabel('Mês')
                plt.ylabel('Valor de Juros')
                plt.title('Gráfico de Linha de Lucro')

                plt.grid(False)  # Add grid lines
                plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
                plt.tight_layout()  # Ensure labels fit within the figure
                plt.show()

        else:

            unique_amounts1 = (
                filtered_dates1.groupby(by=["Ano", "Meses"])["Valor de Juros"]
                .sum()
                .reset_index()
                .sort_values(by="Valor de Juros", ascending=True)
            )


            # Combine "Ano" and "Meses" into a single label
            unique_amounts1["Ano-Meses"] = unique_amounts1["Meses"] + "-" + unique_amounts1["Ano"]

            data_inicio = unique_amounts1["Ano-Meses"]
            valor_juros = unique_amounts1["Valor de Juros"]
            plt.figure(figsize=(8, 6))  # Set the figure size
            plt.plot(data_inicio, valor_juros, marker='o', linestyle='-')  # Line plot with markers

            for i in range(len(data_inicio)):
                    formatted_value = '{:.2f}'.format(valor_juros[i])
                    plt.text(data_inicio[i], valor_juros[i], formatted_value, ha='right', va='bottom')
            plt.xlabel('Ano-Meses')
            plt.ylabel('Valor de Juros')
            plt.title('Gráfico de Linha de Lucro')

            plt.grid(False)  # Add grid lines
            plt.xticks(rotation=45)  # Rotate x-axis labels for better readability
            plt.tight_layout()
        
        st.pyplot(plt)   
        
# Center the chart within the main content area
 

#    c1, c2= st.columns(2)
    
#    with c1:
#        st.plotly_chart(fig)
#    with c2:
#        st.plotly_chart(fig)


# In[ ]:





# In[ ]:




